<?php

declare(strict_types=1);

namespace Mata\Models;

use DateInterval;
use DateTimeImmutable;
use Mata\Core\DatabaseInterface;
use PDOException;

/** Logical alarms: one policy result per event. */
final class AlarmRepository
{
    public function __construct(private DatabaseInterface $db) {}

    /**
     * @throws PDOException
     */
    public function createForEvent(int $eventId): int
    {
        try {
            return (int) $this->db->runQuery(
                'INSERT INTO alarms (event_id, created_at) VALUES (?, CURRENT_TIMESTAMP)',
                [$eventId]
            );
        } catch (PDOException $exception) {
            $rows = $this->db->runQuery('SELECT id FROM alarms WHERE event_id = ?', [$eventId]);
            if (is_array($rows) && isset($rows[0]['id'])) {
                return (int) $rows[0]['id'];
            }

            throw $exception;
        }
    }

    /** @return array<int,array<string,mixed>> */
    public function getLatest(int $limit = 50, bool $unacknowledgedOnly = false): array
    {
        $limit = max(1, $limit);
        $where = $unacknowledgedOnly ? 'WHERE al.acknowledged_at IS NULL' : '';

        $sql = <<<SQL
            SELECT
                al.id,
                al.event_id,
                al.created_at,
                al.acknowledged_at,
                al.acknowledged_by,
                e.datetime,
                COALESCE(e.type, 'alarm') AS type,
                COALESCE(e.message, '') AS message,
                COALESCE(e.level, 'info') AS level,
                e.context,
                COALESCE(s.name, '') AS server,
                COALESCE(a.code, '') AS app,
                a.app_env
            FROM alarms AS al
            LEFT JOIN events AS e ON e.id = al.event_id
            LEFT JOIN servers AS s ON s.id = e.server_id
            LEFT JOIN applications AS a ON a.id = e.application_id
            {$where}
            ORDER BY al.created_at DESC
            LIMIT {$limit}
        SQL;

        $alarms = $this->db->runQuery($sql);
        if (!is_array($alarms) || $alarms === []) {
            return [];
        }

        $alarmIds = array_map(static fn (array $alarm) => (int) $alarm['id'], $alarms);
        $deliveriesByAlarm = $this->fetchDeliveriesByAlarm($alarmIds);

        foreach ($alarms as &$alarm) {
            // (object) keeps empty deliveries encoding as {} instead of [] in JSON.
            $alarm['deliveries'] = $deliveriesByAlarm[(int) $alarm['id']] ?? (object) [];
        }
        unset($alarm);

        return $alarms;
    }

    /**
     * @param array<int,int> $alarmIds
     *
     * @return array<int,array<string,array<string,mixed>>> deliveries grouped by alarm id, keyed by channel
     */
    private function fetchDeliveriesByAlarm(array $alarmIds): array
    {
        $placeholders = implode(', ', array_fill(0, count($alarmIds), '?'));
        $deliveries = $this->db->runQuery(<<<SQL
            SELECT alarm_id, channel, status, attempts, processed_at, next_retry_at
            FROM alarm_deliveries
            WHERE alarm_id IN ({$placeholders})
            ORDER BY alarm_id, channel
            SQL, $alarmIds);

        $grouped = [];
        foreach (is_array($deliveries) ? $deliveries : [] as $delivery) {
            $channel = strtolower(trim((string) $delivery['channel']));
            if ($channel === '') {
                continue;
            }

            $grouped[(int) $delivery['alarm_id']][$channel] = [
                'status' => (string) $delivery['status'],
                'attempts' => (int) $delivery['attempts'],
                'processed_at' => $delivery['processed_at'] === null ? null : (string) $delivery['processed_at'],
                'next_retry_at' => $delivery['next_retry_at'] === null ? null : (string) $delivery['next_retry_at'],
            ];
        }

        return $grouped;
    }

    /** @return array<string,int> */
    public function getUnacknowledgedCounts(): array
    {
        $rows = $this->db->runQuery(<<<'SQL'
            SELECT COALESCE(e.level, 'info') AS level, COUNT(*) AS cnt
            FROM alarms AS al
            LEFT JOIN events AS e ON e.id = al.event_id
            WHERE al.acknowledged_at IS NULL
            GROUP BY COALESCE(e.level, 'info')
            SQL);

        $counts = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $counts[(string) $row['level']] = (int) $row['cnt'];
        }

        return $counts;
    }

    public function acknowledge(int $alarmId, string $user): bool
    {
        return (int) $this->db->runQuery(
            'UPDATE alarms SET acknowledged_at = CURRENT_TIMESTAMP, acknowledged_by = ? WHERE id = ? AND acknowledged_at IS NULL',
            [$user, $alarmId]
        ) > 0;
    }

    public function acknowledgeAll(string $user): int
    {
        return (int) $this->db->runQuery(
            'UPDATE alarms SET acknowledged_at = CURRENT_TIMESTAMP, acknowledged_by = ? WHERE acknowledged_at IS NULL',
            [$user]
        );
    }

    public function clearOld(int $days): void
    {
        $cutoff = (new DateTimeImmutable('now'))
            ->sub(new DateInterval(sprintf('P%dD', $days)))
            ->format('Y-m-d H:i:s');
        $this->db->runQuery('DELETE FROM alarms WHERE created_at < :cutoff', ['cutoff' => $cutoff]);
    }

    /** @return array<int,array<string,mixed>> */
    public function getLastByContext(
        ?string $type,
        ?string $message,
        ?int $serverId,
        ?int $applicationId,
        int $maxAgeMinutes
    ): array {
        $sql = <<<'SQL'
            SELECT al.id, al.created_at, e.type, e.message, e.level, e.context,
                s.name AS server, a.code AS app, a.app_env
            FROM alarms AS al
            LEFT JOIN events AS e ON e.id = al.event_id
            LEFT JOIN servers AS s ON s.id = e.server_id
            LEFT JOIN applications AS a ON a.id = e.application_id
            SQL;
        $where = [];
        $params = [];
        foreach ([
            ['e.type', $type],
            ['e.message', $message],
            ['e.server_id', $serverId],
            ['e.application_id', $applicationId],
        ] as [$column, $value]) {
            if ($value !== null && $value !== '') {
                $where[] = "{$column} = ?";
                $params[] = $value;
            }
        }
        if ($maxAgeMinutes > 0) {
            $where[] = 'al.created_at >= ?';
            $params[] = (new DateTimeImmutable('now'))
                ->sub(new DateInterval(sprintf('PT%dM', $maxAgeMinutes)))
                ->format('Y-m-d H:i:s');
        }
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY al.created_at DESC';

        $result = $this->db->runQuery($sql, $params);

        return is_array($result) ? $result : [];
    }
}
