<?php

declare(strict_types=1);

namespace Watcher\Models;

use DateInterval;
use DateTimeImmutable;
use Watcher\Core\DatabaseInterface;

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
            INSERT INTO dashboard_event_log
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
            FROM dashboard_cooldowns
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
            $sql = 'DELETE FROM dashboard_cooldowns WHERE cooldown_hash = ?';
            $this->db->runQuery($sql, [$cooldownHash]);

            return;
        }

        $expiresAt = (new DateTimeImmutable('now'))->add(new DateInterval(sprintf('PT%dM', $cooldownMinutes)));
        $expiry = $expiresAt->format('Y-m-d H:i:s');

        $sql = <<<'SQL'
            INSERT INTO dashboard_cooldowns (cooldown_hash, cooldown_until, created_at, updated_at)
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
            FROM dashboard_cooldowns
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
        $sql = 'SELECT del.* FROM dashboard_event_log AS del';
        $where = [];
        $params = [];

        if ($type !== null && $type !== '') {
            $where[] = '`type` = ?';
            $params[] = $type;
        }
        if ($serverId !== null) {
            $where[] = 'del.`server_id` = ?';
            $params[] = $serverId;
        }
        if ($applicationId !== null) {
            $where[] = 'del.`application_id` = ?';
            $params[] = $applicationId;
        }
        if ($context !== null) {
            $where[] = 'del.`context` = ?';
            $params[] = $context;
        }
        if ($maxAgeMinutes > 0) {
            $where[] = 'del.`datetime` >= NOW() - INTERVAL ? MINUTE';
            $params[] = $maxAgeMinutes;
        }

        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' ORDER BY del.`datetime` DESC';

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
                dal.seen_at,
                e.type,
                e.message,
                e.level,
                e.context,
                s.name AS server,
                a.code AS app
            FROM dashboard_alarm_log AS dal
            LEFT JOIN dashboard_event_log AS e ON e.id = dal.event_id
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
                del.*,
                s.name AS server,
                a.code AS app
            FROM dashboard_event_log AS del
            LEFT JOIN servers AS s ON del.server_id = s.id
            LEFT JOIN applications AS a ON del.application_id = a.id
            ORDER BY del.`datetime` DESC
            LIMIT {$limit}
        SQL;

        $result = $this->db->runQuery($sql);

        return is_array($result) ? $result : [];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function getLatestAlarms(int $limit = 50): array
    {
        $limit = max(1, (int) $limit);

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
                a.code AS app
            FROM dashboard_alarm_log AS dal
            LEFT JOIN dashboard_event_log AS e ON dal.event_id = e.id
            LEFT JOIN servers AS s ON e.server_id = s.id
            LEFT JOIN applications AS a ON e.application_id = a.id
            ORDER BY dal.created_at DESC
            LIMIT {$limit}
        SQL;

        $result = $this->db->runQuery($sql);

        return is_array($result) ? $result : [];
    }

    /**
     * Remove alarm records older than specified days.
     */
    public function clearOldAlarms(int $days = 365): void
    {
        $sql = 'DELETE FROM dashboard_alarm_log WHERE created_at < NOW() - INTERVAL :days DAY';
        $this->db->runQuery($sql, ['days' => $days]);
    }

    /**
     * Remove event records older than specified days.
     */
    public function clearOldEvents(int $days = 180): void
    {
        $sql = 'DELETE FROM dashboard_event_log WHERE datetime < NOW() - INTERVAL :days DAY';
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
                a.display_name AS app_name
            FROM dashboard_event_log AS e
            LEFT JOIN dashboard_alarm_log AS q ON q.event_id = e.id
            LEFT JOIN servers AS s ON e.server_id = s.id
            LEFT JOIN applications AS a ON e.application_id = a.id
            WHERE q.id IS NULL
              AND e.seen_at IS NULL
              {$levelClause}
            ORDER BY e.datetime ASC
            LIMIT {$limit}
        SQL;

        $result = $this->db->runQuery($sql, $params);

        return is_array($result) ? $result : [];
    }

    public function markEventEvaluated(int $eventId): void
    {
        // Reuse seen_at as the alert-decision marker to avoid reprocessing.
        $this->db->runQuery('UPDATE dashboard_event_log SET seen_at = NOW() WHERE id = ?', [$eventId]);
    }
}
