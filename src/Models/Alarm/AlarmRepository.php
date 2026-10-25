<?php

declare(strict_types=1);

namespace Mata\Models\Alarm;

use DateInterval;
use DateTimeImmutable;
use Mata\Database\DatabaseInterface;
use PDOException;

/** Logical alarms: one policy result per event. */
final class AlarmRepository
{
    public function __construct(private DatabaseInterface $db) {}

    /**
     * @param array<string,mixed> $triggerEvidence
     *
     * @throws PDOException
     */
    public function createForEvent(int $eventId, array $triggerEvidence = []): int
    {
        try {
            $evidence = $triggerEvidence === [] ? null : json_encode($triggerEvidence, JSON_UNESCAPED_SLASHES);

            return $this->db->runInsert(
                'INSERT INTO alarms (event_id, trigger_evidence, created_at) VALUES (?, ?, CURRENT_TIMESTAMP)',
                [$eventId, $evidence === false ? null : $evidence]
            );
        } catch (PDOException $exception) {
            $rows = $this->db->runSelect('SELECT id FROM alarms WHERE event_id = ?', [$eventId]);
            if (isset($rows[0]['id'])) {
                return (int) $rows[0]['id'];
            }

            throw $exception;
        }
    }

    /**
     * Newest first, each with its deliveries by channel.
     *
     * @return array<int,array<string,mixed>>
     */
    public function getLatest(int $limit = 50, bool $unacknowledgedOnly = false, ?string $server = null, ?int $eventId = null): array
    {
        $limit = max(1, $limit);
        $conditions = [];
        $params = [];
        if ($unacknowledgedOnly) {
            $conditions[] = 'al.acknowledged_at IS NULL';
        }
        if ($server !== null) {
            $conditions[] = 's.name = ?';
            $params[] = $server;
        }
        if ($eventId !== null) {
            $conditions[] = 'al.event_id = ?';
            $params[] = $eventId;
        }
        $where = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);

        $sql = <<<SQL
            SELECT
                al.id,
                al.event_id,
                al.created_at,
                al.acknowledged_at,
                al.acknowledged_by,
                al.trigger_evidence,
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

        $alarms = $this->db->runSelect($sql, $params);
        if ($alarms === []) {
            return [];
        }

        $alarmIds = array_map(static fn (array $alarm) => (int) $alarm['id'], $alarms);
        $deliveriesByAlarm = $this->fetchDeliveriesByAlarm($alarmIds);

        foreach ($alarms as &$alarm) {
            $evidence = is_string($alarm['trigger_evidence']) ? json_decode($alarm['trigger_evidence'], true) : null;
            $alarm['trigger_evidence'] = is_array($evidence) ? $evidence : null;
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
        $deliveries = $this->db->runSelect(<<<SQL
            SELECT alarm_id, channel, status, attempts, processed_at, next_retry_at
            FROM alarm_deliveries
            WHERE alarm_id IN ({$placeholders})
            ORDER BY alarm_id, channel
            SQL, $alarmIds);

        $grouped = [];
        foreach ($deliveries as $delivery) {
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
        $rows = $this->db->runSelect(<<<'SQL'
            SELECT COALESCE(e.level, 'info') AS level, COUNT(*) AS cnt
            FROM alarms AS al
            LEFT JOIN events AS e ON e.id = al.event_id
            WHERE al.acknowledged_at IS NULL
            GROUP BY COALESCE(e.level, 'info')
            SQL);

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['level']] = (int) $row['cnt'];
        }

        return $counts;
    }

    public function acknowledge(int $alarmId, string $user): bool
    {
        return $this->db->runStatement(
            'UPDATE alarms SET acknowledged_at = CURRENT_TIMESTAMP, acknowledged_by = ? WHERE id = ? AND acknowledged_at IS NULL',
            [$user, $alarmId]
        ) > 0;
    }

    public function acknowledgeAll(string $user): int
    {
        return $this->db->runStatement(
            'UPDATE alarms SET acknowledged_at = CURRENT_TIMESTAMP, acknowledged_by = ? WHERE acknowledged_at IS NULL',
            [$user]
        );
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

        return $this->db->runSelect($sql, $params);
    }
}
