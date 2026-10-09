<?php

declare(strict_types=1);

namespace Mata\Models;

use DateInterval;
use DateTimeImmutable;
use Mata\Database\DatabaseInterface;
use Mata\Services\Alarm\Dto\QueuedAlarmDelivery;
use PDOException;

/** Persistence operations for channel-specific alarm deliveries. */
final class AlarmDeliveryRepository
{
    private const SQL_DATETIME = 'Y-m-d H:i:s';

    /** Maximum expected dispatch batch runtime before recovery. */
    private const STALE_PROCESSING_MINUTES = 15;

    public function __construct(private DatabaseInterface $db) {}

    /** @throws PDOException */
    public function enqueue(int $alarmId, string $channel, int $priority = 100): int
    {
        $channel = strtolower(trim($channel));

        try {
            return $this->db->runInsert(
                "INSERT INTO alarm_deliveries
                    (alarm_id, channel, status, priority, attempts, created_at, processed_at, next_retry_at, error_payload)
                 VALUES (?, ?, 'pending', ?, 0, CURRENT_TIMESTAMP, NULL, NULL, NULL)",
                [$alarmId, $channel, $priority]
            );
        } catch (PDOException $exception) {
            // Unique (alarm_id, channel) violation: the delivery is already queued.
            return $this->findExistingId($alarmId, $channel) ?? throw $exception;
        }
    }

    /**
     * Claims due pending/retry deliveries and stale processing deliveries.
     *
     * @return array<int,QueuedAlarmDelivery>
     */
    public function claimAvailable(DateTimeImmutable $now, int $limit = 50): array
    {
        $nowSql = $now->format(self::SQL_DATETIME);
        $staleSql = $now
            ->sub(new DateInterval('PT' . self::STALE_PROCESSING_MINUTES . 'M'))
            ->format(self::SQL_DATETIME);
        $limit = max(1, $limit);
        $rows = $this->db->runSelect(<<<SQL
            SELECT ad.id, al.id AS alarm_id, al.created_at AS alarm_created_at, al.event_id, al.trigger_evidence,
                (
                    SELECT MAX(previous_alarm.created_at)
                    FROM alarms AS previous_alarm
                    INNER JOIN events AS previous_event ON previous_event.id = previous_alarm.event_id
                    WHERE previous_event.type = e.type
                      AND (
                          previous_alarm.created_at < al.created_at
                          OR (previous_alarm.created_at = al.created_at AND previous_alarm.id < al.id)
                      )
                      AND (previous_event.server_id = e.server_id OR (previous_event.server_id IS NULL AND e.server_id IS NULL))
                      AND (previous_event.application_id = e.application_id OR (previous_event.application_id IS NULL AND e.application_id IS NULL))
                ) AS previous_alarm_created_at,
                ad.channel, ad.priority, ad.attempts, ad.next_retry_at, ad.created_at,
                e.datetime AS event_datetime, e.type, e.message, e.level, e.context,
                COALESCE(s.name, '') AS server_name,
                COALESCE(a.display_name, a.code, '') AS app_name,
                a.app_env AS app_environment
            FROM alarm_deliveries AS ad
            INNER JOIN alarms AS al ON al.id = ad.alarm_id
            INNER JOIN events AS e ON e.id = al.event_id
            LEFT JOIN servers AS s ON s.id = e.server_id
            LEFT JOIN applications AS a ON a.id = e.application_id
            WHERE (
                    ad.status IN ('pending', 'retry')
                    AND (ad.next_retry_at IS NULL OR ad.next_retry_at <= ?)
                )
                OR (
                    ad.status = 'processing'
                    AND COALESCE(ad.processed_at, ad.created_at) <= ?
                )
            ORDER BY ad.priority ASC, ad.created_at ASC
            LIMIT {$limit}
            SQL, [$nowSql, $staleSql]);
        if ($rows === []) {
            return [];
        }

        $ids = array_column($rows, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $this->db->runStatement(
            "UPDATE alarm_deliveries SET status = 'processing', processed_at = ? WHERE id IN ({$placeholders})",
            array_merge([$nowSql], $ids)
        );

        return array_map(fn (array $row): QueuedAlarmDelivery => $this->hydrate($row), $rows);
    }

    /**
     * @param array<int,array{server:string,channel:string}> $pairs
     *
     * @return array<string,array<string,array{minute:int,hour:int,day:int}>>
     */
    public function getRecentDispatchCounts(array $pairs, DateTimeImmutable $now): array
    {
        if ($pairs === []) {
            return [];
        }
        $rows = $this->db->runSelect(<<<'SQL'
            SELECT LOWER(COALESCE(s.name, 'global')) AS server_key, LOWER(ad.channel) AS channel,
                SUM(CASE WHEN ad.processed_at >= :minute_threshold THEN 1 ELSE 0 END) AS minute_count,
                SUM(CASE WHEN ad.processed_at >= :hour_threshold THEN 1 ELSE 0 END) AS hour_count,
                COUNT(*) AS day_count
            FROM alarm_deliveries AS ad
            INNER JOIN alarms AS al ON al.id = ad.alarm_id
            LEFT JOIN events AS e ON e.id = al.event_id
            LEFT JOIN servers AS s ON s.id = e.server_id
            WHERE ad.status = 'sent' AND ad.processed_at IS NOT NULL
              AND ad.processed_at >= :day_threshold
            GROUP BY server_key, channel
            SQL, [
            ':minute_threshold' => $now->sub(new DateInterval('PT1M'))->format(self::SQL_DATETIME),
            ':hour_threshold' => $now->sub(new DateInterval('PT1H'))->format(self::SQL_DATETIME),
            ':day_threshold' => $now->sub(new DateInterval('P1D'))->format(self::SQL_DATETIME),
        ]);

        $lookup = [];
        foreach ($pairs as $pair) {
            $lookup[strtolower($pair['server'])][strtolower($pair['channel'])] = ['minute' => 0, 'hour' => 0, 'day' => 0];
        }
        foreach ($rows as $row) {
            $server = (string) ($row['server_key'] ?? 'global');
            $channel = (string) ($row['channel'] ?? '');
            if (isset($lookup[$server][$channel])) {
                $lookup[$server][$channel] = [
                    'minute' => (int) $row['minute_count'],
                    'hour' => (int) $row['hour_count'],
                    'day' => (int) $row['day_count'],
                ];
            }
        }

        return $lookup;
    }

    public function updateError(int $deliveryId, ?string $errorPayload): void
    {
        $this->db->runStatement(
            'UPDATE alarm_deliveries SET error_payload = ?, processed_at = CURRENT_TIMESTAMP WHERE id = ?',
            [$errorPayload, $deliveryId]
        );
    }

    public function updateStatus(int $deliveryId, string $status, int $attempts, ?DateTimeImmutable $nextRetryAt = null): void
    {
        $this->db->runStatement(
            'UPDATE alarm_deliveries SET status = ?, attempts = ?, next_retry_at = ?, processed_at = CURRENT_TIMESTAMP WHERE id = ?',
            [$status, $attempts, $nextRetryAt?->format(self::SQL_DATETIME), $deliveryId]
        );
    }

    private function findExistingId(int $alarmId, string $channel): ?int
    {
        $rows = $this->db->runSelect(
            'SELECT id FROM alarm_deliveries WHERE alarm_id = ? AND channel = ?',
            [$alarmId, $channel]
        );

        return isset($rows[0]['id']) ? (int) $rows[0]['id'] : null;
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): QueuedAlarmDelivery
    {
        return new QueuedAlarmDelivery(
            id: (int) $row['id'],
            alarmId: (int) ($row['alarm_id'] ?? 0),
            alarmCreatedAt: empty($row['alarm_created_at'])
                ? new DateTimeImmutable()
                : new DateTimeImmutable((string) $row['alarm_created_at']),
            eventId: (int) $row['event_id'],
            eventOccurredAt: new DateTimeImmutable((string) $row['event_datetime']),
            channel: strtolower((string) $row['channel']),
            priority: (int) $row['priority'],
            attempts: (int) $row['attempts'],
            nextRetryAt: empty($row['next_retry_at']) ? null : new DateTimeImmutable((string) $row['next_retry_at']),
            type: (string) ($row['type'] ?? 'alarm'),
            message: (string) ($row['message'] ?? ''),
            level: (string) ($row['level'] ?? 'info'),
            context: is_string($row['context'] ?? null) ? $row['context'] : null,
            serverName: $row['server_name'] !== '' ? (string) $row['server_name'] : null,
            appName: $row['app_name'] !== '' ? (string) $row['app_name'] : null,
            appEnvironment: is_string($row['app_environment'] ?? null) && trim($row['app_environment']) !== ''
                ? $row['app_environment']
                : null,
            createdAt: empty($row['created_at']) ? new DateTimeImmutable() : new DateTimeImmutable((string) $row['created_at']),
            previousAlarmCreatedAt: empty($row['previous_alarm_created_at'])
                ? null
                : new DateTimeImmutable((string) $row['previous_alarm_created_at']),
            triggerEvidence: $this->triggerEvidence($row['trigger_evidence'] ?? null),
        );
    }

    /** @return array<string,mixed> */
    private function triggerEvidence(mixed $storedEvidence): array
    {
        $evidence = is_string($storedEvidence) ? json_decode($storedEvidence, true) : null;

        return is_array($evidence) ? $evidence : [];
    }
}
