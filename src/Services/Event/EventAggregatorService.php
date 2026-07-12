<?php

declare(strict_types=1);

namespace Mata\Services\Event;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use Mata\Models\AlarmRepository;
use Mata\Models\EventRepository;
use Mata\Services\Foundation\IdResolver;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Event aggregator and query service for decision engines.
 */
class EventAggregatorService implements EventAggregatorServiceInterface
{
    private const DEFAULT_ALARM_DECISION_LIMIT = 100;
    private const DEFAULT_LATEST_EVENTS_LIMIT = 50;
    private const DEFAULT_LATEST_ALARMS_LIMIT = 50;
    private const DEFAULT_DECISION_WINDOW_MINUTES = [
        '1h' => 60,
        '4h' => 240,
        '8h' => 480,
        '24h' => 1440,
        '72h' => 4320,
    ];
    private const LEVEL_RANKS = [
        'critical' => 4,
        'error' => 3,
        'warning' => 2,
        'info' => 1,
    ];

    private EventRepository $repository;
    private AlarmRepository $alarmRepository;
    private IdResolver $idResolver;
    private LoggerInterface $logger;

    /** @var array<string,int> */
    private array $decisionCountSpans;

    /**
     * @param null|array<string,int> $decisionCountSpans
     */
    public function __construct(
        EventRepository $repository,
        AlarmRepository $alarmRepository,
        IdResolver $idResolver,
        ?LoggerInterface $logger = null,
        ?array $decisionCountSpans = null
    ) {
        $this->repository = $repository;
        $this->alarmRepository = $alarmRepository;
        $this->idResolver = $idResolver;
        $this->logger = $logger ?? new NullLogger();
        $this->decisionCountSpans = $this->normalizeDecisionCountSpans(
            $decisionCountSpans ?? self::DEFAULT_DECISION_WINDOW_MINUTES
        );
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
        $events = $this->repository->getAlarmDecisionCandidates($limit, $levelFilter);

        if ($events === []) {
            return [];
        }

        foreach ($events as $index => $event) {
            $events[$index]['metrics'] = $this->buildDecisionMetrics($event);
        }

        return $events;
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
     * @param array<string,mixed> $event
     *
     * @return array<string,mixed>
     */
    private function buildDecisionMetrics(array $event): array
    {
        $metrics = $this->buildSameTypeLevelOrHigherMetrics($event);

        return [
            'counts' => [
                'same_type_level_or_higher' => $metrics['counts'],
            ],
            'last_event_at' => $metrics['last_event_at'],
            'last_alarm_at' => $metrics['last_alarm_at'],
        ];
    }

    /**
     * @param array<string,mixed> $event
     *
     * @return array{counts: array<string,int>, last_event_at: null|string, last_alarm_at: null|string}
     */
    private function buildSameTypeLevelOrHigherMetrics(array $event): array
    {
        $type = $this->normalizeString($event['type'] ?? null);
        if ($type === null || $this->decisionCountSpans === []) {
            return [
                'counts' => [],
                'last_event_at' => null,
                'last_alarm_at' => null,
            ];
        }

        $serverId = isset($event['server_id']) ? (int) $event['server_id'] : null;
        $applicationId = isset($event['application_id']) ? (int) $event['application_id'] : null;
        $levelRank = $this->levelRank((string) ($event['level'] ?? 'info'));
        $maxAgeMinutes = max($this->decisionCountSpans);

        [$recentEvents, $recentAlarms] = $this->fetchRecentEventsAndAlarms(
            $type,
            $serverId,
            $applicationId,
            $maxAgeMinutes
        );

        return $this->aggregateSameTypeLevelOrHigherMetrics(
            $recentEvents,
            $recentAlarms,
            $levelRank
        );
    }

    /**
     * @param array<string,int> $spans
     *
     * @return array<string,int>
     */
    private function normalizeDecisionCountSpans(array $spans): array
    {
        $normalized = [];
        foreach ($spans as $label => $minutes) {
            if ($label === '') {
                continue;
            }
            $minutes = (int) $minutes;
            if ($minutes <= 0) {
                continue;
            }
            $normalized[$label] = $minutes;
        }

        return $normalized;
    }

    private function levelRank(string $level): int
    {
        $level = strtolower($level);
        if (!isset(self::LEVEL_RANKS[$level])) {
            $this->logger->warning("Invalid alarm level: {$level}");

            return 0;
        }

        return self::LEVEL_RANKS[$level];
    }

    private function parseEventTime(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function normalizeString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
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

    /**
     * @return array{0: array<int,array<string,mixed>>, 1: array<int,array<string,mixed>>}
     */
    private function fetchRecentEventsAndAlarms(
        string $type,
        ?int $serverId,
        ?int $applicationId,
        int $maxAgeMinutes
    ): array {
        $recentEvents = $this->repository->getLastEventsByContext(
            type: $type,
            context: null,
            serverId: $serverId,
            applicationId: $applicationId,
            maxAgeMinutes: $maxAgeMinutes
        );

        $recentAlarms = $this->alarmRepository->getLastByContext(
            type: $type,
            message: null,
            serverId: $serverId,
            applicationId: $applicationId,
            maxAgeMinutes: $maxAgeMinutes
        );

        return [$recentEvents, $recentAlarms];
    }

    /**
     * @param array<int,array<string,mixed>> $recentEvents
     * @param array<int,array<string,mixed>> $recentAlarms
     *
     * @return array{counts: array<string,int>, last_event_at: null|string, last_alarm_at: null|string}
     */
    private function aggregateSameTypeLevelOrHigherMetrics(
        array $recentEvents,
        array $recentAlarms,
        int $levelRank
    ): array {
        $now = new DateTimeImmutable();
        $spanData = $this->buildSpanThresholds($now);
        $spanThresholds = $spanData['thresholds'];
        $spanCounts = $spanData['counts'];

        $lastEventAt = null;
        foreach ($recentEvents as $recentEvent) {
            if ($this->levelRank((string) ($recentEvent['level'] ?? 'info')) < $levelRank) {
                continue;
            }

            $eventTime = $this->parseEventTime($recentEvent['datetime'] ?? null);
            if ($eventTime === null) {
                continue;
            }

            if ($lastEventAt === null || $eventTime > $lastEventAt) {
                $lastEventAt = $eventTime;
            }

            $this->updateSpanCounts($eventTime, $spanThresholds, $spanCounts);
        }

        $lastAlarmAt = null;
        foreach ($recentAlarms as $recentAlarm) {
            if ($this->levelRank((string) ($recentAlarm['level'] ?? 'info')) < $levelRank) {
                continue;
            }

            $alarmTime = $this->parseEventTime($recentAlarm['created_at'] ?? null);
            if ($alarmTime === null) {
                continue;
            }

            if ($lastAlarmAt === null || $alarmTime > $lastAlarmAt) {
                $lastAlarmAt = $alarmTime;
            }
        }

        return [
            'counts' => $spanCounts,
            'last_event_at' => $lastEventAt?->format(DateTimeInterface::ATOM),
            'last_alarm_at' => $lastAlarmAt?->format(DateTimeInterface::ATOM),
        ];
    }

    /**
     * @return array{thresholds: array<string,DateTimeImmutable>, counts: array<string,int>}
     */
    private function buildSpanThresholds(DateTimeImmutable $now): array
    {
        $spanThresholds = [];
        $spanCounts = [];
        foreach ($this->decisionCountSpans as $span => $minutes) {
            $minutes = max(0, (int) $minutes);
            $spanCounts[$span] = 0;
            $spanThresholds[$span] = $minutes > 0
                ? $now->sub(new DateInterval('PT' . $minutes . 'M'))
                : $now;
        }

        return [
            'thresholds' => $spanThresholds,
            'counts' => $spanCounts,
        ];
    }

    /**
     * @param array<string,DateTimeImmutable> $spanThresholds
     * @param array<string,int>               $spanCounts
     */
    private function updateSpanCounts(
        DateTimeImmutable $eventTime,
        array $spanThresholds,
        array &$spanCounts
    ): void {
        foreach ($spanThresholds as $span => $threshold) {
            if ($eventTime >= $threshold) {
                ++$spanCounts[$span];
            }
        }
    }
}
