<?php

declare(strict_types=1);

namespace Mata\Models;

use DateInterval;
use DateTimeImmutable;
use Mata\Core\DatabaseInterface;
use Mata\Services\Alert\Dto\QueuedAlert;
use PDOException;

/**
 * Repository encapsulating access to the alert queue table.
 *
 * Handles all reads/writes to `alarms` to keep SQL details centralised.
 */
class AlertQueueRepository
{
    private DatabaseInterface $db;

    public function __construct(DatabaseInterface $db)
    {
        $this->db = $db;
    }

    /**
     * Enqueue a pending alert for the dispatcher.
     *
     * @throws PDOException when the insert fails
     */
    public function enqueue(int $eventId, string $channel, int $priority = 100): int
    {
        $sql = <<<'SQL'
            INSERT INTO alarms
                (event_id, channel, status, priority, attempts, created_at, processed_at, next_retry_at, error_payload)
            VALUES
                (?, ?, 'pending', ?, 0, NOW(), NULL, NULL, NULL)
        SQL;

        return (int) $this->db->runQuery($sql, [$eventId, $channel, $priority]);
    }

    /**
     * Claim a batch of pending alerts for processing.
     *
     * @return array<int,QueuedAlert>
     */
    public function claimPending(DateTimeImmutable $now, int $limit = 50): array
    {
        $cutoff = $now->format('Y-m-d H:i:s');
        $limit = max(1, (int) $limit);

        $sql = <<<SQL
            SELECT
                al.id,
                al.event_id,
                al.channel,
                al.priority,
                al.attempts,
                al.next_retry_at,
                al.created_at,
                e.type,
                e.message,
                e.level,
                e.context,
                COALESCE(s.name, '') AS server_name,
                COALESCE(a.display_name, a.code, '') AS app_name
            FROM alarms AS al
            LEFT JOIN events AS e ON e.id = al.event_id
            LEFT JOIN servers AS s ON e.server_id = s.id
            LEFT JOIN applications AS a ON e.application_id = a.id
            WHERE
                (
                    al.status = 'pending'
                    OR (al.status = 'retry' AND (al.next_retry_at IS NULL OR al.next_retry_at <= ?))
                )
            ORDER BY al.priority ASC, al.created_at ASC
            LIMIT {$limit}
        SQL;

        $rows = $this->db->runQuery($sql, [$cutoff]);

        if (!is_array($rows) || $rows === []) {
            return [];
        }

        $ids = array_column($rows, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $updateSql = <<<SQL
            UPDATE alarms
               SET status = 'processing',
                   processed_at = ?
             WHERE id IN ({$placeholders})
        SQL;

        $updateParams = array_merge([$cutoff], $ids);
        $this->db->runQuery($updateSql, $updateParams);

        $alerts = [];
        foreach ($rows as $row) {
            $alerts[] = $this->hydrateQueuedAlert($row);
        }

        return $alerts;
    }

    /**
     * Fetch recent dispatch counts for throttling decisions.
     *
     * @param array<int,array{server:string,channel:string}> $pairs
     *
     * @return array<string,array<string,array{minute:int,hour:int,day:int}>>
     */
    public function getRecentDispatchCounts(array $pairs, DateTimeImmutable $now): array
    {
        if ($pairs === []) {
            return [];
        }

        $minuteThreshold = $now->sub(new DateInterval('PT1M'))->format('Y-m-d H:i:s');
        $hourThreshold = $now->sub(new DateInterval('PT1H'))->format('Y-m-d H:i:s');
        $dayThreshold = $now->sub(new DateInterval('P1D'))->format('Y-m-d H:i:s');

        $sql = <<<'SQL'
            SELECT
                LOWER(COALESCE(s.name, 'global')) AS server_key,
                LOWER(al.channel) AS channel,
                SUM(CASE WHEN al.processed_at >= :minute_threshold THEN 1 ELSE 0 END) AS minute_count,
                SUM(CASE WHEN al.processed_at >= :hour_threshold THEN 1 ELSE 0 END) AS hour_count,
                COUNT(*) AS day_count
            FROM alarms AS al
            LEFT JOIN events AS e ON e.id = al.event_id
            LEFT JOIN servers AS s ON e.server_id = s.id
            WHERE al.status = 'sent'
              AND al.processed_at IS NOT NULL
              AND al.processed_at >= :day_threshold
            GROUP BY server_key, channel
        SQL;

        $rows = $this->db->runQuery($sql, [
            ':minute_threshold' => $minuteThreshold,
            ':hour_threshold' => $hourThreshold,
            ':day_threshold' => $dayThreshold,
        ]);

        if (!is_array($rows)) {
            return [];
        }

        $lookup = [];
        foreach ($pairs as $pair) {
            $server = strtolower($pair['server']);
            $channel = strtolower($pair['channel']);
            $lookup[$server][$channel] = ['minute' => 0, 'hour' => 0, 'day' => 0];
        }

        foreach ($rows as $row) {
            $server = (string) ($row['server_key'] ?? 'global');
            $channel = (string) ($row['channel'] ?? '');
            if (isset($lookup[$server][$channel])) {
                $lookup[$server][$channel] = [
                    'minute' => (int) ($row['minute_count'] ?? 0),
                    'hour' => (int) ($row['hour_count'] ?? 0),
                    'day' => (int) ($row['day_count'] ?? 0),
                ];
            }
        }

        return $lookup;
    }

    /**
     * Record last error payload for debugging dispatch failures.
     */
    public function updateError(int $alarmId, ?string $errorPayload): void
    {
        $sql = <<<'SQL'
            UPDATE alarms
               SET error_payload = ?, processed_at = NOW()
             WHERE id = ?
        SQL;

        $this->db->runQuery($sql, [$errorPayload, $alarmId]);
    }

    /**
     * Update status and tracking fields for an alarm entry.
     */
    public function updateStatus(
        int $alarmId,
        string $status,
        int $attempts,
        ?DateTimeImmutable $nextRetryAt = null
    ): void {
        $sql = <<<'SQL'
            UPDATE alarms
               SET status = ?,
                   attempts = ?,
                   next_retry_at = ?,
                   processed_at = NOW()
             WHERE id = ?
        SQL;

        $nextRetry = $nextRetryAt?->format('Y-m-d H:i:s');

        $this->db->runQuery($sql, [$status, $attempts, $nextRetry, $alarmId]);
    }

    /**
     * @param array<string,mixed> $row
     */
    private function hydrateQueuedAlert(array $row): QueuedAlert
    {
        $nextRetryAt = null;
        if (!empty($row['next_retry_at'])) {
            $nextRetryAt = new DateTimeImmutable((string) $row['next_retry_at']);
        }

        $createdAt = !empty($row['created_at'])
            ? new DateTimeImmutable((string) $row['created_at'])
            : new DateTimeImmutable();

        return new QueuedAlert(
            id: (int) $row['id'],
            eventId: (int) $row['event_id'],
            channel: strtolower((string) $row['channel']),
            priority: (int) $row['priority'],
            attempts: (int) $row['attempts'],
            nextRetryAt: $nextRetryAt,
            type: (string) ($row['type'] ?? 'alert'),
            message: (string) ($row['message'] ?? ''),
            level: (string) ($row['level'] ?? 'info'),
            context: is_string($row['context'] ?? null) ? (string) $row['context'] : null,
            serverName: $row['server_name'] !== '' ? (string) $row['server_name'] : null,
            appName: $row['app_name'] !== '' ? (string) $row['app_name'] : null,
            createdAt: $createdAt
        );
    }
}
