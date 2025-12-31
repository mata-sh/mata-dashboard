<?php

declare(strict_types=1);

namespace Watcher\Models;

use DateInterval;
use DateTimeImmutable;
use Watcher\Core\DatabaseInterface;
use Watcher\Models\Dto\EventRecordResult;
use Watcher\Services\Foundation\IdResolver;

/**
 * Event logging and alarm management system.
 *
 * Handles comprehensive event tracking:
 * - Event logging to database with context information
 * - Alarm persistence alongside logged events
 * - Event querying with flexible filtering
 * - Automatic cleanup of old records
 * - Dual-table system (events and alarms)
 */
class EventRecorder
{
    protected DatabaseInterface $db;
    private IdResolver $idResolver;

    /**
     * @param DatabaseInterface $db         Primary database connection
     * @param null|IdResolver   $idResolver Optional identifier resolver (default: new instance)
     */
    public function __construct(
        DatabaseInterface $db,
        ?IdResolver $idResolver = null,
    ) {
        $this->db = $db;
        $this->idResolver = $idResolver ?? new IdResolver();
    }

    /**
     * Persist an event (and optional alarm) to the database.
     *
     * @param string          $type            Event type identifier
     * @param string          $message         Human readable message
     * @param string          $context         Optional structured context payload (JSON string)
     * @param string          $level           Severity level: info, warning, critical
     * @param null|int|string $server          Server identifier (name or ID)
     * @param null|int|string $app             Application identifier (code or ID)
     * @param array<string>   $channels        Notification/alarm channels
     * @param bool            $isAlarm         Whether to persist an alarm entry alongside the event
     * @param null|string     $cooldownHash    Deterministic hash used to manage event cooldowns
     * @param int             $cooldownMinutes Cooldown interval in minutes
     *
     * @return EventRecordResult Event recording result with IDs and resolved context
     */
    public function recordEvent(
        string $type,
        string $message,
        string $context = '',
        string $level = 'info',
        int|string|null $server = null,
        int|string|null $app = null,
        array $channels = [],
        bool $isAlarm = false,
        ?string $cooldownHash = null,
        int $cooldownMinutes = 0,
    ): EventRecordResult {
        $serverContext = $this->idResolver->resolveServer($server);
        $applicationContext = $this->idResolver->resolveApplication($app);

        $serverId = $serverContext['id'];
        $applicationId = $applicationContext['id'];
        $serverLabel = $serverContext['name'] ?? (is_scalar($server) ? (string) $server : null);
        $appLabel = $applicationContext['name'] ?? (is_scalar($app) ? (string) $app : null);
        $serverKey = is_string($server) ? trim($server) : ($serverLabel ?? null);

        $primaryChannel = $this->resolvePrimaryAlarmChannel($channels);

        $sql = <<<'SQL'
            INSERT INTO dashboard_event_log
                (`type`, `message`, `level`, `context`, `server_id`, `application_id`, `datetime`, `alarm_channel`)
            VALUES
                (?, ?, ?, ?, ?, ?, NOW(), ?)
        SQL;

        $eventInsertResult = $this->db->runQuery($sql, [
            $type,
            $message,
            $level,
            $context,
            $serverId,
            $applicationId,
            $primaryChannel,
        ]);
        $eventId = is_int($eventInsertResult) ? $eventInsertResult : 0;

        if ($cooldownHash !== null && $cooldownHash !== '') {
            $this->upsertCooldown($cooldownHash, $cooldownMinutes);
        }

        return new EventRecordResult(
            eventId: $eventId,
            alarmId: null,
            serverId: $serverId,
            appId: $applicationId,
            serverLabel: $serverLabel,
            appLabel: $appLabel,
            serverKey: $serverKey
        );
    }

    /**
     * Determine which channel is persisted alongside the event.
     *
     * Only legacy channels (email/log/sms) are stored; others return null.
     *
     * @param array<int,string> $channels
     */
    private function resolvePrimaryAlarmChannel(array $channels): ?string
    {
        $legacyChannels = ['email', 'log', 'sms'];

        foreach ($channels as $channel) {
            $normalized = strtolower(trim((string) $channel));
            if (in_array($normalized, $legacyChannels, true)) {
                return $normalized;
            }
        }

        return null;
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

    private function upsertCooldown(string $cooldownHash, int $cooldownMinutes): void
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
     * Batch check for active alarm cooldowns.
     *
     * Efficiently checks multiple alarm hashes at once to determine which are still on cooldown.
     * Used for per-channel alarm throttling to filter out channels that are still in cooldown period.
     *
     * @param array<string> $alarmHashes Array of alarm cooldown hashes to check
     *
     * @return array<string> Array of hashes that ARE currently on cooldown
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
     * Set or update alarm cooldown period.
     *
     * Wrapper around upsertCooldown for semantic clarity when handling alarm cooldowns.
     * Alarms use per-channel cooldowns (hash includes channel name), while events use empty channel.
     *
     * @param string $alarmHash       SHA256 hash of type|server|app|context|channel
     * @param int    $cooldownMinutes Cooldown duration in minutes
     */
    public function upsertAlarmCooldown(string $alarmHash, int $cooldownMinutes): void
    {
        $this->upsertCooldown($alarmHash, $cooldownMinutes);
    }

    /**
     * Query events by context with flexible filtering.
     *
     * @param null|string     $type          Event type filter (default: null)
     * @param null|string     $context       Context payload filter (default: null)
     * @param null|int|string $server        Server filter (default: null)
     * @param null|int|string $app           App filter (default: null)
     * @param int             $maxAgeMinutes Maximum age in minutes (0 = no limit, default: 0)
     *
     * @return array<int,array<string,mixed>> Array of matching event records
     */
    public function getLastEventsByContext(
        ?string $type = null,
        ?string $context = null,
        int|string|null $server = null,
        int|string|null $app = null,
        int $maxAgeMinutes = 0
    ): array {
        $serverContext = $this->idResolver->resolveServer($server);
        $serverId = $serverContext['id'];
        if ($server !== null && $server !== '' && $serverId === null) {
            return [];
        }

        $applicationContext = $this->idResolver->resolveApplication($app);
        $applicationId = $applicationContext['id'];
        if ($app !== null && $app !== '' && $applicationId === null) {
            return [];
        }

        $sql = 'SELECT 1 FROM dashboard_event_log AS del';
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

        $sql .= ' ORDER BY del.`datetime` DESC LIMIT 1';

        $result = $this->db->runQuery($sql, $params);

        return is_array($result) ? $result : [];
    }

    /**
     * Query alarm events with flexible filtering.
     *
     * @param null|string     $type          Event type filter
     * @param null|string     $message       Message filter
     * @param null|int|string $server        Server filter
     * @param null|int|string $app           App filter
     * @param int             $maxAgeMinutes Maximum age in minutes (0 = no limit)
     *
     * @return array<int,array<string,mixed>> Array of matching alarm records
     */
    public function getLastAlarmsByContext(
        ?string $type = null,
        ?string $message = null,
        int|string|null $server = null,
        int|string|null $app = null,
        int $maxAgeMinutes = 0
    ): array {
        $serverContext = $this->idResolver->resolveServer($server);
        $serverId = $serverContext['id'];
        if ($server !== null && $server !== '' && $serverId === null) {
            return [];
        }

        $applicationContext = $this->idResolver->resolveApplication($app);
        $applicationId = $applicationContext['id'];
        if ($app !== null && $app !== '' && $applicationId === null) {
            return [];
        }

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

    // public function getLastAlarmsByContext(
    //     string $type = '',
    //     string $message = '',
    //     string $channel = '',
    //     string $server = '',
    //     string $app = '',
    //     int $maxAgeMinutes = 0
    // ): array {
    //     $sql = <<<SQL
    //         SELECT `id`, `channel`, `type`, `message`, `level`, `server`, `app`, `action`, `datetime`
    //         FROM dashboard_event_log
    //         WHERE (`type` = '' OR `type` = ?)
    //         AND (`message` = '' OR `message` = ?)
    //         AND (`channel` = '' OR `channel` = ?)
    //         AND (`server` = '' OR `server` = ?)
    //         AND (`app` = '' OR `app` = ?)
    //     SQL;

    //     $params = [
    //         $type,
    //         $message,
    //         $channel,
    //         $server,
    //         $app
    //     ];

    //     if ($maxAgeMinutes > 0) {
    //         $sql .= " AND `datetime` >= NOW() - INTERVAL :age MINUTE";
    //         $params[] = $maxAgeMinutes;
    //     }

    //     $sql .= " ORDER BY `datetime` DESC";

    //     return $this->db->runQuery($sql, $params);
    // }

    /** @return array<int,array<string,mixed>> */
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

    /** @return array<int,array<string,mixed>> */
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
                e.alarm_channel,
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
        $sql = 'DELETE FROM dashboard_alarm_log WHERE datetime < NOW() - INTERVAL :days DAY';
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
}
