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
                (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
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
              AND cooldown_until > CURRENT_TIMESTAMP
            LIMIT 1
        SQL;

        $result = $this->db->runQuery($sql, [$cooldownHash]);

        return is_array($result) && !empty($result);
    }

    // NOTE: uses MySQL-only `ON DUPLICATE KEY UPDATE` upsert syntax (SQLite uses
    // `ON CONFLICT ... DO UPDATE`), so this method cannot run against the SQLite
    // integration harness without a dialect-aware upsert abstraction.
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
            VALUES (?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ON DUPLICATE KEY UPDATE
                cooldown_until = VALUES(cooldown_until),
                updated_at = CURRENT_TIMESTAMP
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
              AND cooldown_until > CURRENT_TIMESTAMP
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
            $where[] = 'e.`datetime` >= ?';
            $params[] = (new DateTimeImmutable('now'))
                ->sub(new DateInterval(sprintf('PT%dM', $maxAgeMinutes)))
                ->format('Y-m-d H:i:s');
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
     * Get unevaluated events for alarm decision processing.
     *
     * @param int                       $limit       Maximum number of candidates to fetch
     * @param null|array<string>|string $levelFilter Filter by severity level(s) (critical, warning, info)
     *
     * @return array<int,array<string,mixed>>
     */
    public function getAlarmDecisionCandidates(int $limit = 100, array|string|null $levelFilter = null): array
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

    public function markEventEvaluated(int $eventId, ?array $alarmDecision = null): void
    {
        if ($alarmDecision === null) {
            $this->db->runQuery(
                'UPDATE events SET evaluated_at = CURRENT_TIMESTAMP, alarm_decision = NULL WHERE id = ?',
                [$eventId]
            );

            return;
        }

        $encodedDecision = json_encode($alarmDecision, JSON_UNESCAPED_SLASHES);
        if ($encodedDecision === false) {
            $this->db->runQuery(
                'UPDATE events SET evaluated_at = CURRENT_TIMESTAMP, alarm_decision = NULL WHERE id = ?',
                [$eventId]
            );

            return;
        }

        $this->db->runQuery(
            'UPDATE events SET evaluated_at = CURRENT_TIMESTAMP, alarm_decision = ? WHERE id = ?',
            [$encodedDecision, $eventId]
        );
    }
}
