<?php

declare(strict_types=1);

namespace Watcher\Services\Foundation;

use Watcher\Core\DatabaseInterface;
use Watcher\Models\Dto\EventRecordResult;
use Watcher\Models\EventRepository;

/**
 * Event logging and alarm management service.
 *
 * Handles event persistence and cooldown tracking:
 * - Event logging to database with context information
 * - Alarm cooldown management
 */
class EventRecorderService
{
    private EventRepository $repository;
    private IdResolver $idResolver;

    /**
     * @param DatabaseInterface    $db         Primary database connection
     * @param null|IdResolver      $idResolver Optional identifier resolver (default: new instance)
     * @param null|EventRepository $repository Optional repository override
     */
    public function __construct(
        DatabaseInterface $db,
        ?IdResolver $idResolver = null,
        ?EventRepository $repository = null
    ) {
        $this->repository = $repository ?? new EventRepository($db);
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

        $eventId = $this->repository->insertEvent(
            type: $type,
            message: $message,
            level: $level,
            context: $context,
            serverId: $serverId,
            applicationId: $applicationId,
            primaryChannel: $primaryChannel
        );

        if ($cooldownHash !== null && $cooldownHash !== '') {
            $this->repository->upsertCooldown($cooldownHash, $cooldownMinutes);
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
        return $this->repository->hasActiveCooldown($cooldownHash);
    }

    public function upsertAlarmCooldown(string $alarmHash, int $cooldownMinutes): void
    {
        $this->repository->upsertCooldown($alarmHash, $cooldownMinutes);
    }

    /**
     * @param array<string> $alarmHashes
     *
     * @return array<string>
     */
    public function getActiveAlarmCooldowns(array $alarmHashes): array
    {
        return $this->repository->getActiveAlarmCooldowns($alarmHashes);
    }
}
