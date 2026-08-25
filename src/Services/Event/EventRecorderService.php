<?php

declare(strict_types=1);

namespace Mata\Services\Event;

use Mata\Database\DatabaseInterface;
use Mata\Models\Dto\EventRecordResult;
use Mata\Models\EventRepository;
use Mata\Services\Identity\ServerApplicationIdResolver;

/**
 * Event logging service.
 *
 * Handles event persistence and cooldown tracking:
 * - Event logging to database with context information
 * - Alarm cooldown management
 */
class EventRecorderService
{
    private EventRepository $repository;
    private ServerApplicationIdResolver $idResolver;

    /**
     * @param DatabaseInterface                $db         Primary database connection
     * @param null|ServerApplicationIdResolver $idResolver Optional identifier resolver (default: new instance)
     * @param null|EventRepository             $repository Optional repository override
     */
    public function __construct(
        DatabaseInterface $db,
        ?ServerApplicationIdResolver $idResolver = null,
        ?EventRepository $repository = null
    ) {
        $this->repository = $repository ?? new EventRepository($db);
        $this->idResolver = $idResolver ?? new ServerApplicationIdResolver();
    }

    /**
     * Persist an event to the database.
     *
     * @param string          $type            Event type identifier
     * @param string          $message         Human readable message
     * @param string          $context         Optional structured context payload (JSON string)
     * @param string          $level           Severity level: info, warning, critical
     * @param null|int|string $server          Server identifier (name or ID)
     * @param null|int|string $app             Application identifier (code or ID)
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

        $eventId = $this->repository->insertEvent(
            type: $type,
            message: $message,
            level: $level,
            context: $context,
            serverId: $serverId,
            applicationId: $applicationId,
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
