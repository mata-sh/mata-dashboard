<?php

declare(strict_types=1);

namespace Mata\Models;

use DateInterval;
use DateTimeImmutable;
use Mata\Core\DatabaseInterface;

/**
 * Repository encapsulating access to dashboard event and alarm tables.
 */
class EventRepository
{
    private DatabaseInterface $db;

    public function __construct(DatabaseInterface $db)
    {
        $this->db = $db;
    }

    public function insertEvent(
        string $type,
        string $message,
        string $level,
        string $context,
        ?int $serverId,
        ?int $applicationId,
    ): int {
        $sql = <<<'SQL'
            INSERT INTO events
                (`type`, `message`, `level`, `context`, `server_id`, `application_id`, `datetime`)
            VALUES
                (?, ?, ?, ?, ?, ?, NOW())
        SQL;

        $result = $this->db->runQuery($sql, [
            $type,
            $message,
            $level,
            $context,
            $serverId,
            $applicationId,
        ]);

        return is_int($result) ? $result : 0;
    }

    public function hasActiveCooldown(string $cooldownHash): bool
    {
        if ($cooldownHash === '') {
            return false;
        }

        $sql = <<<'SQL'
            SELECT 1
            FROM cooldowns
            WHERE cooldown_hash = ?
              AND cooldown_until > NOW()
            LIMIT 1
        SQL;

        $result = $this->db->runQuery($sql, [$cooldownHash]);

        return is_array($result) && !empty($result);
    }

    public function upsertCooldown(string $cooldownHash, int $cooldownMinutes): void
    {
        if ($cooldownHash === '') {
            return;
        }

        if ($cooldownMinutes <= 0) {
            $sql = 'DELETE FROM cooldowns WHERE cooldown_hash = ?';
            $this->db->runQuery($sql, [$cooldownHash]);

            return;
        }

        $expiresAt = (new DateTimeImmutable('now'))->add(new DateInterval(sprintf('PT%dM', $cooldownMinutes)));
        $expiry = $expiresAt->format('Y-m-d H:i:s');

        $sql = <<<'SQL'
            INSERT INTO cooldowns (cooldown_hash, cooldown_until, created_at, updated_at)
            VALUES (?, ?, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                cooldown_until = VALUES(cooldown_until),
                updated_at = NOW()
        SQL;

        $this->db->runQuery($sql, [$cooldownHash, $expiry]);
    }

    /**
     * @param array<string> $alarmHashes
     *
     * @return array<string>
     */
    public function getActiveAlarmCooldowns(array $alarmHashes): array
    {
        if (empty($alarmHashes)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($alarmHashes), '?'));

        $sql = <<<SQL
            SELECT cooldown_hash
            FROM cooldowns
            WHERE cooldown_hash IN ({$placeholders})
              AND cooldown_until > NOW()
        SQL;

        $result = $this->db->runQuery($sql, $alarmHashes);

        if (!is_array($result)) {
            return [];
        }

        return array_column($result, 'cooldown_hash');
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function getLastEventsByContext(
        ?string $type,
        ?string $context,
        ?int $serverId,
        ?int $applicationId,
        int $maxAgeMinutes
    ): array {
        $sql = 'SELECT e.* FROM events AS e';
        $where = [];
        $params = [];

        if ($type !== null && $type !== '') {
            $where[] = '`type` = ?';
            $params[] = $type;
        }
        if ($serverId !== null) {
            $where[] = 'e.`server_id` = ?';
            $params[] = $serverId;
        }
        if ($applicationId !== null) {
            $where[] = 'e.`application_id` = ?';
            $params[] = $applicationId;
        }
        if ($context !== null) {
            $where[] = 'e.`context` = ?';
            $params[] = $context;
        }
        if ($maxAgeMinutes > 0) {
            $where[] = 'e.`datetime` >= NOW() - INTERVAL ? MINUTE';
            $params[] = $maxAgeMinutes;
        }

        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' ORDER BY e.`datetime` DESC';

        $result = $this->db->runQuery($sql, $params);

        return is_array($result) ? $result : [];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function getLastAlarmsByContext(
        ?string $type,
        ?string $message,
        ?int $serverId,
        ?int $applicationId,
        int $maxAgeMinutes
    ): array {
        $sql = <<<'SQL'
            SELECT
                dal.id,
                dal.channel,
                dal.status,
                dal.created_at,
                dal.processed_at,
                e.type,
                e.message,
                e.level,
                e.context,
                s.name AS server,
                a.code AS app,
                a.app_env AS app_env
            FROM alarms AS dal
            LEFT JOIN events AS e ON e.id = dal.event_id
            LEFT JOIN servers AS s ON e.server_id = s.id
            LEFT JOIN applications AS a ON e.application_id = a.id
        SQL;
        $where = [];
        $params = [];

        if ($type !== null && $type !== '') {
            $where[] = 'e.`type` = ?';
            $params[] = $type;
        }
        if ($message !== null && $message !== '') {
            $where[] = 'e.`message` = ?';
            $params[] = $message;
        }
        if ($serverId !== null) {
            $where[] = 'e.`server_id` = ?';
            $params[] = $serverId;
        }
        if ($applicationId !== null) {
            $where[] = 'e.`application_id` = ?';
            $params[] = $applicationId;
        }
        if ($maxAgeMinutes > 0) {
            $where[] = 'dal.`created_at` >= NOW() - INTERVAL ? MINUTE';
            $params[] = $maxAgeMinutes;
        }

        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' ORDER BY dal.`created_at` DESC';

        $result = $this->db->runQuery($sql, $params);

        return is_array($result) ? $result : [];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function getLatestEvents(int $limit = 50): array
    {
        $limit = max(1, (int) $limit);

        $sql = <<<SQL
            SELECT
                e.*,
                s.name AS server,
                a.code AS app,
                a.app_env AS app_env
            FROM events AS e
            LEFT JOIN servers AS s ON e.server_id = s.id
            LEFT JOIN applications AS a ON e.application_id = a.id
            ORDER BY e.`datetime` DESC
            LIMIT {$limit}
        SQL;

        $result = $this->db->runQuery($sql);

        return is_array($result) ? $result : [];
    }

    /**
     * @param int  $limit              Maximum number of rows
     * @param bool $unacknowledgedOnly Only alarms no user has acknowledged yet
     *
     * @return array<int,array<string,mixed>>
     */
    public function getLatestAlarms(int $limit = 50, bool $unacknowledgedOnly = false): array
    {
        $limit = max(1, (int) $limit);
        $whereClause = $unacknowledgedOnly ? 'WHERE dal.acknowledged_at IS NULL' : '';

        $sql = <<<SQL
            SELECT
                dal.*,
                e.datetime,
                e.type,
                e.message,
                e.level,
                e.context,
                dal.channel,
                s.name AS server,
                a.code AS app,
                a.app_env AS app_env
            FROM alarms AS dal
            LEFT JOIN events AS e ON dal.event_id = e.id
            LEFT JOIN servers AS s ON e.server_id = s.id
            LEFT JOIN applications AS a ON e.application_id = a.id
            {$whereClause}
            ORDER BY dal.created_at DESC
            LIMIT {$limit}
        SQL;

        $result = $this->db->runQuery($sql);

        return is_array($result) ? $result : [];
    }

    /**
     * Count unacknowledged alarms grouped by the underlying event's severity level.
     *
     * Hot path: polled by the topbar notification bell of every open dashboard.
     * Alarms without a joined event count as 'info'.
     *
     * @return array<string,int> level => count
     */
    public function getUnacknowledgedAlarmCounts(): array
    {
        $sql = <<<'SQL'
            SELECT COALESCE(e.level, 'info') AS level, COUNT(*) AS cnt
            FROM alarms AS dal
            LEFT JOIN events AS e ON dal.event_id = e.id
            WHERE dal.acknowledged_at IS NULL
            GROUP BY COALESCE(e.level, 'info')
        SQL;

        $result = $this->db->runQuery($sql);

        $counts = [];
        if (is_array($result)) {
            foreach ($result as $row) {
                $counts[(string) $row['level']] = (int) $row['cnt'];
            }
        }

        return $counts;
    }

    /**
     * Acknowledge a single alarm for a user.
     *
     * The acknowledged_at IS NULL guard keeps the first acknowledger on record
     * when two users act concurrently.
     *
     * @return bool True if the alarm was newly acknowledged
     */
    public function acknowledgeAlarm(int $alarmId, string $user): bool
    {
        $sql = <<<'SQL'
            UPDATE alarms
               SET acknowledged_at = NOW(), acknowledged_by = ?
             WHERE id = ? AND acknowledged_at IS NULL
        SQL;

        return (int) $this->db->runQuery($sql, [$user, $alarmId]) > 0;
    }

    /**
     * Acknowledge all unacknowledged alarms for a user.
     *
     * @return int Number of alarms acknowledged
     */
    public function acknowledgeAllAlarms(string $user): int
    {
        $sql = <<<'SQL'
            UPDATE alarms
               SET acknowledged_at = NOW(), acknowledged_by = ?
             WHERE acknowledged_at IS NULL
        SQL;

        return (int) $this->db->runQuery($sql, [$user]);
    }

    /**
     * Remove alarm records older than specified days.
     */
    public function clearOldAlarms(int $days = 365): void
    {
        $sql = 'DELETE FROM alarms WHERE created_at < NOW() - INTERVAL :days DAY';
        $this->db->runQuery($sql, ['days' => $days]);
    }

    /**
     * Remove event records older than specified days.
     */
    public function clearOldEvents(int $days = 180): void
    {
        $sql = 'DELETE FROM events WHERE datetime < NOW() - INTERVAL :days DAY';
        $this->db->runQuery($sql, ['days' => $days]);
    }

    /**
     * Get unevaluated events for alarm decision processing.
     *
     * @param int                       $limit       Maximum number of candidates to fetch
     * @param null|array<string>|string $levelFilter Filter by severity level(s) (critical, warning, info)
     *
     * @return array<int,array<string,mixed>>
     */
    public function getAlertDecisionCandidates(int $limit = 100, array|string|null $levelFilter = null): array
    {
        $limit = max(1, (int) $limit);

        $params = [];
        $levelClause = '';

        if ($levelFilter !== null) {
            $levels = is_array($levelFilter) ? $levelFilter : [$levelFilter];
            $placeholders = implode(',', array_fill(0, count($levels), '?'));
            $levelClause = " AND e.level IN ({$placeholders})";
            $params = $levels;
        }

        $sql = <<<SQL
            SELECT
                e.id,
                e.type,
                e.message,
                e.level,
                e.context,
                e.datetime,
                e.server_id,
                e.application_id,
                s.name AS server_name,
                a.code AS app_code,
                a.display_name AS app_name,
                a.app_env AS app_env
            FROM events AS e
            LEFT JOIN alarms AS al ON al.event_id = e.id
            LEFT JOIN servers AS s ON e.server_id = s.id
            LEFT JOIN applications AS a ON e.application_id = a.id
            WHERE al.id IS NULL
              AND e.evaluated_at IS NULL
              {$levelClause}
            ORDER BY e.datetime ASC
            LIMIT {$limit}
        SQL;

        $result = $this->db->runQuery($sql, $params);

        return is_array($result) ? $result : [];
    }

    public function markEventEvaluated(int $eventId): void
    {
        // Reuse evaluated_at as the alert-decision marker to avoid reprocessing.
        $this->db->runQuery('UPDATE events SET evaluated_at = NOW() WHERE id = ?', [$eventId]);
    }
}
