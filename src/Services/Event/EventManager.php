<?php

declare(strict_types=1);

namespace Mata\Services\Event;

use LogicException;
use Mata\Config\Dto\ApplicationConfig;
use Mata\Config\Monitoring\Loader\ConfigLoadProblem;
use Mata\Config\Monitoring\MonitoringConfig;
use Mata\Services\Alarm\AlarmDirectProcessor;
use Mata\Services\Alarm\Dto\Alarm;
use Mata\Services\Alarm\Dto\AlarmLevel;
use Mata\Services\Event\Dto\EventCommand;
use PDOException;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;

/**
 * Monitoring events.
 *
 * PRIMARY FACADE for event operations - use this in most cases.
 *
 * ## Architecture & Usage Patterns
 *
 * EventManager provides:
 * - Event threshold evaluation (server metrics, app metrics)
 * - Event creation with cooldown deduplication via handle()
 * - Read access to stored events via the public aggregator property
 *
 * ### Recommended Usage:
 *
 * **Most cases: Inject EventManager**
 * ```php
 * // Business logic + orchestrated write
 * $eventManager->handle(new EventCommand(type: $type, message: $message, context: $context, level: $level));
 *
 * // Threshold evaluation
 * $level = $eventManager->getServerDataThresholdLevel($value, 'cpu_usage');
 *
 * // Read operations via public property
 * $events = $eventManager->aggregator->getLatestEvents();
 * $events = $eventManager->aggregator->getLastEventsByContext(...);
 * ```
 *
 * **Specialized: Inject specific service**
 * Use when you ONLY need read operations (e.g., reporting, cleanup crons):
 * ```php
 * // Read-only operations
 * class ReportGenerator {
 *     public function __construct(private EventAggregatorServiceInterface $aggregator) {}
 * }
 * ```
 *
 * ### Component Responsibilities:
 * - **EventManager** (this): Event business logic, threshold evaluation, event cooldown management
 * - **AlarmThresholdCooldownService**: Alarm threshold checks, cooldown management, channel filtering
 * - **EventRecorderService**: Low-level event/alarm persistence and cooldown tracking
 * - **EventAggregatorService**: Event/alarm querying, reporting, cleanup operations
 * - **AlarmDirectProcessor**: Direct alarm queueing for forced alarms
 *
 * @see AlarmThresholdCooldownService For alarm threshold and cooldown logic
 * @see EventRecorderService For event persistence behind handle()
 * @see EventAggregatorServiceInterface For read-only event querying
 */
class EventManager
{
    /**
     * Default cooldown interval for unconfigured event types (in minutes)
     * Prevents spam for event types without specific configuration.
     */
    private const DEFAULT_EVENT_INTERVAL_MINUTES = 30;

    /** @var array<string,array<string,float|int>> Server metric thresholds */
    private array $serverDataThresholds = [];

    /** @var array<string,array<string,float|int>> Application metric thresholds */
    private array $appDataThresholds = [];

    /** @var array<string,int> Event type to interval minutes mapping */
    private array $eventIntervals = [];

    private ?AlarmDirectProcessor $alarmDirectProcessor;
    private ApplicationConfig $appConfig;
    private CooldownHashFactory $cooldownHashFactory;
    private LoggerInterface $logger;

    /** Event recorder for persistence and cooldown tracking; writes go through handle(). */
    private ?EventRecorderService $recorder = null;

    /**
     * Event aggregator for querying and reporting.
     * Public property for direct read access (common usage pattern).
     */
    public ?EventAggregatorServiceInterface $aggregator = null;

    /**
     * Initialize event manager with optional dependencies.
     *
     * Supports fluent initialization via setter methods for deferred dependency injection.
     * This allows EventManager to be created before loading monitoring configuration.
     *
     * Use setMonitoringConfig() to configure thresholds and intervals after initialization.
     *
     * @param AlarmDirectProcessor                 $alarmDirectProcessor Direct alarm processor for forced alarms
     * @param ApplicationConfig                    $appConfig            Application configuration for alarm/event behavior
     * @param null|EventRecorderService            $eventRecorder        Event recorder; required before events are handled
     * @param null|EventAggregatorServiceInterface $eventAggregator      Optional event aggregator (can be set later via setEventAggregator)
     * @param null|CooldownHashFactory             $cooldownHashFactory  Optional cooldown hash factory
     * @param null|LoggerInterface                 $logger               Receives events that could not be stored
     */
    public function __construct(
        ApplicationConfig $appConfig,
        ?AlarmDirectProcessor $alarmDirectProcessor = null,
        ?EventRecorderService $eventRecorder = null,
        ?EventAggregatorServiceInterface $eventAggregator = null,
        ?CooldownHashFactory $cooldownHashFactory = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->alarmDirectProcessor = $alarmDirectProcessor;
        $this->appConfig = $appConfig;
        $this->recorder = $eventRecorder;
        $this->aggregator = $eventAggregator;
        $this->cooldownHashFactory = $cooldownHashFactory ?? new CooldownHashFactory();
        $this->logger = $logger ?? new NullLogger();
    }

    /** Attach alarm processing once alarm limits are loaded; forced alarms are skipped before that. */
    public function setAlarmDirectProcessor(AlarmDirectProcessor $alarmDirectProcessor): self
    {
        $this->alarmDirectProcessor = $alarmDirectProcessor;

        return $this;
    }

    /**
     * Set event aggregator service (fluent interface).
     *
     * @param EventAggregatorServiceInterface $aggregator Event aggregator instance
     *
     * @return $this For method chaining
     */
    public function setEventAggregator(EventAggregatorServiceInterface $aggregator): self
    {
        $this->aggregator = $aggregator;

        return $this;
    }

    /** Configure event thresholds and intervals from loaded monitoring configuration. */
    public function setMonitoringConfig(MonitoringConfig $monitoringConfig): self
    {
        $this->serverDataThresholds = $monitoringConfig->thresholds->serverData;
        $this->appDataThresholds = $monitoringConfig->thresholds->appData;
        $this->eventIntervals = $monitoringConfig->intervals->eventMinimumMinutes;

        return $this;
    }

    /**
     * Get threshold level for server metric value.
     *
     * Evaluates a server metric against configured thresholds to determine severity level.
     * Returns the highest threshold level that the value exceeds.
     *
     * @param int    $value Current metric value to evaluate
     * @param string $name  Metric name (cpu_usage, disk_space_usage_percent, total_system_filecount, etc.)
     *
     * @return string Threshold level ('info', 'warning', 'error', 'alarm') or empty string if no threshold exceeded
     */
    public function getServerDataThresholdLevel(int $value, string $name): string
    {
        $result = '';
        $thresholds = $this->serverDataThresholds[$name] ?? [];
        foreach ($thresholds as $level => $limit) {
            if ($value > $limit) {
                $result = $level;
            }
        }

        return $result;
    }

    /**
     * Get a numeric threshold limit for a server metric and level.
     *
     * @return null|float|int Null when the metric level is not configured
     */
    public function getServerDataThresholdLimit(string $name, string $level = 'alarm'): float|int|null
    {
        return $this->serverDataThresholds[$name][$level] ?? null;
    }

    /**
     * Get threshold level for app/application metric value.
     *
     * Evaluates a application metric against configured thresholds to determine severity level.
     * Returns the highest threshold level that the value exceeds.
     *
     * @param int    $value Current metric value to evaluate
     * @param string $name  Metric name (session_count, log_filesize_kb, error_count, etc.)
     *
     * @return string Threshold level ('info', 'warning', 'error', 'alarm') or empty string if no threshold exceeded
     */
    public function getAppDataThresholdLevel(int $value, string $name): string
    {
        $result = '';
        $thresholds = $this->appDataThresholds[$name] ?? [];
        foreach ($thresholds as $level => $limit) {
            if ($value > $limit) {
                $result = $level;
            }
        }

        return $result;
    }

    /**
     * Get the configured limit of one threshold level for an app metric.
     *
     * @param string $name  Metric name (session_count, log_filesize_kb, error_count, etc.)
     * @param string $level Threshold level as returned by getAppDataThresholdLevel()
     */
    public function getAppDataThreshold(string $name, string $level): float|int|null
    {
        return $this->appDataThresholds[$name][$level] ?? null;
    }

    /**
     * Determine if an event should be created based on configuration.
     *
     * Evaluates event creation criteria:
     * - Global event logging enablement
     * - Minimum event intervals to prevent duplicate events
     * - Context-specific event history checking
     *
     * @param string $cooldownHash    Pre-computed cooldown hash for deduplication
     * @param int    $cooldownMinutes Precomputed cooldown interval
     *
     * @return bool True if event should be created
     */
    public function shouldCreateEvent(
        string $cooldownHash,
        int $cooldownMinutes
    ): bool {
        if (!$this->appConfig->general->eventsEnabled) {
            return false;
        }

        if ($cooldownMinutes <= 0) {
            return true;
        }

        if ($cooldownHash === '') {
            return true;
        }

        if ($this->recorder === null) {
            throw new LogicException(
                'EventRecorder not initialized. Pass eventRecorder to the constructor before using shouldCreateEvent().'
            );
        }

        return !$this->recorder->hasActiveCooldown($cooldownHash);
    }

    /**
     * Record what the config loaders found, one event per problem. Before setMonitoringConfig()
     * the default cooldown applies; without a database the problems are logged. The message is
     * the cooldown identity, so each distinct problem is reported once per window.
     *
     * @param list<ConfigLoadProblem> $problems
     */
    public function recordConfigProblems(array $problems): void
    {
        foreach ($problems as $problem) {
            $this->handle(new EventCommand(
                type: $problem->type,
                message: $problem->message,
                level: $problem->level,
                forceAlarm: $problem->forceAlarm,
                context: ['detail' => $problem->message],
            ));
        }
    }

    /**
     * Record an event with cooldown deduplication.
     *
     * @return array{eventRecorded: bool, eventId:?int}
     */
    public function handle(EventCommand $event): array
    {
        $context = $event->eventContext();
        $cooldownMinutes = $this->getEventIntervalMinutes($event->type);
        $cooldownHash = $this->cooldownHashFactory->forEvent(
            type: $event->type,
            server: $event->server,
            app: $event->app,
            context: $context
        );

        try {
            $shouldCreateEvent = $this->shouldCreateEvent(
                cooldownHash: $cooldownHash,
                cooldownMinutes: $cooldownMinutes
            );

            if (!$shouldCreateEvent) {
                return [
                    'eventRecorded' => false,
                    'eventId' => null,
                ];
            }

            if ($this->recorder === null) {
                throw new LogicException(
                    'EventRecorder not initialized. Pass eventRecorder to the constructor before using handle().'
                );
            }

            $eventRecordResult = $this->recorder->recordEvent(
                type: $event->type,
                message: $event->message,
                context: $context->toJson(),
                level: $event->level,
                server: $event->server,
                app: $event->app,
                cooldownHash: $cooldownHash,
                cooldownMinutes: $cooldownMinutes
            );

            if ($event->forceAlarm && $this->alarmDirectProcessor !== null) {
                $alarm = new Alarm(
                    type: $event->type,
                    message: $event->message,
                    level: AlarmLevel::fromString($event->level),
                    server: $eventRecordResult->serverLabel,
                    app: $eventRecordResult->appLabel,
                    channels: ['email']
                );
                $this->alarmDirectProcessor->process($alarm, $eventRecordResult);
            }
            if (!$eventRecordResult->wasRecorded()) {
                $this->logUnstoredEvent($event, 'no database');
            }

            return [
                'eventRecorded' => $eventRecordResult->wasRecorded(),
                'eventId' => $eventRecordResult->eventId > 0 ? $eventRecordResult->eventId : null,
            ];
        } catch (PDOException $exception) {
            $this->logUnstoredEvent($event, $exception->getMessage());

            return [
                'eventRecorded' => false,
                'eventId' => null,
            ];
        }
    }

    /** Keep events visible in no-database mode and when a write fails. */
    private function logUnstoredEvent(EventCommand $event, string $reason): void
    {
        $level = in_array($event->level, [LogLevel::CRITICAL, LogLevel::ERROR, LogLevel::WARNING], true)
            ? $event->level
            : LogLevel::INFO;
        $this->logger->log($level, $event->message, ['event_type' => $event->type, 'event_not_stored' => $reason]);
    }

    /**
     * Get event cooldown interval in minutes for a given event type.
     *
     * Resolution order:
     * 1. Event-specific configuration from intervals.json
     * 2. User-configured default from intervals.json '_default' key
     * 3. Hardcoded fallback (30 minutes) to prevent spam
     *
     * @param string $type Event type identifier
     *
     * @return int Cooldown interval in minutes
     */
    private function getEventIntervalMinutes(string $type): int
    {
        // 1. Check event-specific configuration from intervals.json
        if (isset($this->eventIntervals[$type])) {
            return $this->eventIntervals[$type];
        }

        // 2. Check default from intervals.json
        if (isset($this->eventIntervals['_default'])) {
            return $this->eventIntervals['_default'];
        }

        // 3. Hardcoded fallback
        return self::DEFAULT_EVENT_INTERVAL_MINUTES;
    }
}
