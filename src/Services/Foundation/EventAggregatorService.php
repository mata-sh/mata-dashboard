<?php

declare(strict_types=1);

namespace Watcher\Services\Foundation;

use Watcher\Models\EventRepository;

/**
 * Event aggregator and query service for decision engines.
 */
class EventAggregatorService
{
    private EventRepository $repository;
    private IdResolver $idResolver;

    public function __construct(EventRepository $repository, ?IdResolver $idResolver = null)
    {
        $this->repository = $repository;
        $this->idResolver = $idResolver ?? new IdResolver();
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
        return $this->repository->getAlertDecisionCandidates($limit, $levelFilter);
    }

    public function markEventEvaluated(int $eventId): void
    {
        $this->repository->markEventEvaluated($eventId);
    }

    /**
     * Query events by context with flexible filtering.
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

        return $this->repository->getLastEventsByContext(
            type: $type,
            context: $context,
            serverId: $serverId,
            applicationId: $applicationId,
            maxAgeMinutes: $maxAgeMinutes
        );
    }

    /**
     * Query alarm events with flexible filtering.
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

        return $this->repository->getLastAlarmsByContext(
            type: $type,
            message: $message,
            serverId: $serverId,
            applicationId: $applicationId,
            maxAgeMinutes: $maxAgeMinutes
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function getLatestEvents(int $limit = 50): array
    {
        return $this->repository->getLatestEvents($limit);
    }

    /** @return array<int,array<string,mixed>> */
    public function getLatestAlarms(int $limit = 50): array
    {
        return $this->repository->getLatestAlarms($limit);
    }

    /**
     * Remove alarm records older than specified days.
     */
    public function clearOldAlarms(int $days = 365): void
    {
        $this->repository->clearOldAlarms($days);
    }

    /**
     * Remove event records older than specified days.
     */
    public function clearOldEvents(int $days = 180): void
    {
        $this->repository->clearOldEvents($days);
    }
}
