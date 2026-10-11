<?php

declare(strict_types=1);

namespace Mata\Services\Event;

use Mata\Models\Alarm\AlarmRepository;
use Mata\Models\Event\EventRepository;
use Mata\Services\Identity\ServerApplicationIdResolver;

/**
 * Event aggregator and query service for decision engines.
 */
class EventAggregatorService implements EventAggregatorServiceInterface
{
    private const DEFAULT_ALARM_DECISION_LIMIT = 100;
    private const DEFAULT_LATEST_EVENTS_LIMIT = 50;
    private const DEFAULT_LATEST_ALARMS_LIMIT = 50;
    private EventRepository $repository;
    private AlarmRepository $alarmRepository;
    private ServerApplicationIdResolver $idResolver;

    public function __construct(
        EventRepository $repository,
        AlarmRepository $alarmRepository,
        ServerApplicationIdResolver $idResolver
    ) {
        $this->repository = $repository;
        $this->alarmRepository = $alarmRepository;
        $this->idResolver = $idResolver;
    }

    /**
     * Get unevaluated events for alarm decision processing.
     *
     * @param int                       $limit       Maximum number of candidates to fetch
     * @param null|array<string>|string $levelFilter Filter by severity level(s) (critical, warning, info)
     *
     * @return array<int,array<string,mixed>>
     */
    public function getAlarmDecisionCandidates(
        int $limit = self::DEFAULT_ALARM_DECISION_LIMIT,
        array|string|null $levelFilter = null
    ): array {
        return $this->repository->getAlarmDecisionCandidates($limit, $levelFilter);
    }

    public function markEventEvaluated(int $eventId, ?array $alarmDecision = null): void
    {
        $this->repository->markEventEvaluated($eventId, $alarmDecision);
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
        $contextIds = $this->resolveContextIds($server, $app);
        if ($contextIds === null) {
            return [];
        }
        [$serverId, $applicationId] = $contextIds;

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
        $contextIds = $this->resolveContextIds($server, $app);
        if ($contextIds === null) {
            return [];
        }
        [$serverId, $applicationId] = $contextIds;

        return $this->alarmRepository->getLastByContext(
            type: $type,
            message: $message,
            serverId: $serverId,
            applicationId: $applicationId,
            maxAgeMinutes: $maxAgeMinutes
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function getLatestEvents(int $limit = self::DEFAULT_LATEST_EVENTS_LIMIT): array
    {
        return $this->repository->getLatestEvents($limit);
    }

    /** @return array<int,array<string,mixed>> */
    public function getLatestAlarms(int $limit = self::DEFAULT_LATEST_ALARMS_LIMIT, bool $unacknowledgedOnly = false): array
    {
        return $this->alarmRepository->getLatest($limit, $unacknowledgedOnly);
    }

    /**
     * Count unacknowledged alarms grouped by severity level.
     *
     * @return array<string,int> level => count
     */
    public function getUnacknowledgedAlarmCounts(): array
    {
        return $this->alarmRepository->getUnacknowledgedCounts();
    }

    /**
     * Acknowledge a single alarm for a user.
     */
    public function acknowledgeAlarm(int $alarmId, string $user): bool
    {
        return $this->alarmRepository->acknowledge($alarmId, $user);
    }

    /**
     * Acknowledge all unacknowledged alarms for a user.
     */
    public function acknowledgeAllAlarms(string $user): int
    {
        return $this->alarmRepository->acknowledgeAll($user);
    }

    /**
     * @return null|array{0: null|int, 1: null|int}
     */
    private function resolveContextIds(int|string|null $server, int|string|null $app): ?array
    {
        $server = $server === '' ? null : $server;
        $app = $app === '' ? null : $app;

        $serverContext = $this->idResolver->resolveServer($server);
        $serverId = $serverContext['id'];
        if ($server !== null && $serverId === null) {
            return null;
        }

        $applicationContext = $this->idResolver->resolveApplication($app);
        $applicationId = $applicationContext['id'];
        if ($app !== null && $applicationId === null) {
            return null;
        }

        return [$serverId, $applicationId];
    }
}
