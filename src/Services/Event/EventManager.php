<?php

declare(strict_types=1);

namespace Mata\Services\Event;

use LogicException;
use Mata\Config\Dto\ApplicationConfig;
use Mata\Services\Alarm\AlarmDirectProcessor;
use Mata\Services\Alarm\Dto\Alarm;
use Mata\Services\Alarm\Dto\AlarmLevel;
use Mata\Services\Event\Dto\EventContext;
use PDOException;

/**
 * Monitoring events.
 *
 * PRIMARY FACADE for event operations - use this in most cases.
 *
 * ## Architecture & Usage Patterns
 *
 * EventManager provides:
 * - Event threshold evaluation (server metrics, app metrics)
 * - Event creation with cooldown deduplication via handleEvent()
 * - Direct access to read/write operations via public properties
 *
 * ### Recommended Usage:
 *
 * **Most cases: Inject EventManager**
 * ```php
 * // Business logic + orchestrated write
 * $eventManager->handleEvent($type, $message, $context, $level);
 *
 * // Threshold evaluation
 * $level = $eventManager->getServerDataThresholdLevel($value, 'cpu_usage');
 *
 * // Read operations via public property
 * $events = $eventManager->aggregator->getLatestEvents();
 * $events = $eventManager->aggregator->getLastEventsByContext(...);
 *
 * // Direct write (rare - usually use handleEvent)
 * $eventManager->recorder->recordEvent(...);
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
 * @see EventRecorderService For direct event persistence (rarely used directly)
 * @see EventAggregatorServiceInterface For read-only event querying
 */
class EventManager
{
    /**
     * Default cooldown interval for unconfigured event types (in minutes)
     * Prevents spam for event types without specific configuration.
     */
    private const DEFAULT_EVENT_INTERVAL_MINUTES = 30;

    /** @var array<string,mixed> Server metric thresholds */
    private array $serverDataThresholds = [];

    /** @var array<string,mixed> Application metric thresholds */
    private array $appDataThresholds = [];

    /** @var array<string,int> Event type to interval minutes mapping */
    private array $eventIntervals = [];

    private ?AlarmDirectProcessor $alarmDirectProcessor;
    private ApplicationConfig $appConfig;

    /**
     * Event recorder for persistence and cooldown tracking.
     * Public property for direct access when needed (rare - prefer handleEvent).
     */
    public ?EventRecorderService $recorder = null;

    /**
     * Event aggregator for querying and reporting.
     * Public property for direct read access (common usage pattern).
     */
    public ?EventAggregatorServiceInterface $aggregator = null;

    /**
     * Initialize event manager with optional dependencies.
     *
     * Supports fluent initialization via setter methods for deferred dependency injection.
     * This allows EventManager to be created before loading monitoring settings.
     *
     * Use setMonitoringSettings() to configure thresholds and intervals after initialization.
     *
     * @param AlarmDirectProcessor                 $alarmDirectProcessor Direct alarm processor for forced alarms
     * @param ApplicationConfig                    $appConfig            Application configuration for alarm/event settings
     * @param null|EventRecorderService            $eventRecorder        Optional event recorder (can be set later via setEventRecorder)
     * @param null|EventAggregatorServiceInterface $eventAggregator      Optional event aggregator (can be set later via setEventAggregator)
     */
    public function __construct(
        ApplicationConfig $appConfig,
        ?AlarmDirectProcessor $alarmDirectProcessor = null,
        ?EventRecorderService $eventRecorder = null,
        ?EventAggregatorServiceInterface $eventAggregator = null
    ) {
        $this->alarmDirectProcessor = $alarmDirectProcessor;
        $this->appConfig = $appConfig;
        $this->recorder = $eventRecorder;
        $this->aggregator = $eventAggregator;
    }

    /**
     * Set event recorder service (fluent interface).
     *
     * @param EventRecorderService $recorder Event recorder instance
     *
     * @return $this For method chaining
     */
    public function setEventRecorder(EventRecorderService $recorder): self
    {
        $this->recorder = $recorder;

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

    /**
     * Set monitoring settings (fluent interface).
     *
     * Updates event thresholds and intervals from loaded monitoring configuration.
     *
     * @param array<string,mixed> $settings Monitoring settings containing 'thresholds' and 'intervals' keys
     *
     * @return $this For method chaining
     */
    public function setMonitoringSettings(array $settings): self
    {
        $thresholdsConfig = $settings['thresholds'] ?? [];
        $intervalsConfig = $settings['intervals'] ?? [];

        $this->serverDataThresholds = $thresholdsConfig['server_data'] ?? [];
        $this->appDataThresholds = $thresholdsConfig['app_data'] ?? [];
        $this->eventIntervals = $intervalsConfig['events']['min_event_interval_minutes'] ?? [];

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
                'EventRecorder not initialized. Call setEventRecorder() before using shouldCreateEvent().'
            );
        }

        return !$this->recorder->hasActiveCooldown($cooldownHash);
    }

    /**
     * Record an event with cooldown deduplication.
     *
     * Simplified version that only handles event persistence.
     *
     * @param string                  $type       Event type identifier
     * @param string                  $message    Human readable message
     * @param array<int|string,mixed> $context    Additional context payload
     * @param string                  $level      Severity level (info, warning, critical)
     * @param null|int|string         $server     Server context (name or ID)
     * @param null|int|string         $app        Application context (code or ID)
     * @param mixed                   $forceAlarm
     *
     * @return array{eventRecorded: bool, eventId:?int}
     */
    public function handleEvent(
        string $type,
        string $message,
        array $context = [],
        string $level = 'info',
        int|string|null $server = null,
        int|string|null $app = null,
        $forceAlarm = false
    ): array {
        $eventContext = EventContext::fromArray($context);

        $cooldownMinutes = $this->getEventIntervalMinutes($type);
        $cooldownHash = $this->generateCooldownHash($type, $server, $app, $eventContext, '');

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
                'EventRecorder not initialized. Call setEventRecorder() before using handleEvent().'
            );
        }

        try {
            $eventRecordResult = $this->recorder->recordEvent(
                type: $type,
                message: $message,
                context: $eventContext->toJson(),
                level: $level,
                server: $server,
                app: $app,
                cooldownHash: $cooldownHash,
                cooldownMinutes: $cooldownMinutes
            );

            if ($forceAlarm && $this->alarmDirectProcessor !== null) {
                $alarm = new Alarm(
                    type: $type,
                    message: $message,
                    level: AlarmLevel::fromString($level),
                    server: $eventRecordResult->serverLabel,
                    app: $eventRecordResult->appLabel,
                    channels: ['email']
                );
                $this->alarmDirectProcessor->process($alarm, $eventRecordResult);
            }

            return [
                'eventRecorded' => $eventRecordResult->wasRecorded(),
                'eventId' => $eventRecordResult->eventId > 0 ? $eventRecordResult->eventId : null,
            ];
        } catch (PDOException $exception) {
            return [
                'eventRecorded' => false,
                'eventId' => null,
            ];
        }
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
            return max(0, (int) $this->eventIntervals[$type]);
        }

        // 2. Check default from intervals.json
        if (isset($this->eventIntervals['_default'])) {
            return max(0, (int) $this->eventIntervals['_default']);
        }

        // 3. Hardcoded fallback
        return self::DEFAULT_EVENT_INTERVAL_MINUTES;
    }

    /**
     * Generate cooldown hash for event deduplication.
     *
     * Creates a unique hash for cooldown tracking based on event attributes.
     * Hash format: SHA256(type|server|app|context|channel)
     *
     * NOTE: For event cooldowns, channel should always be empty string.
     * For alarm cooldowns, use AlarmThresholdCooldownService::generateCooldownHash() instead.
     *
     * @param string          $type    Event type identifier
     * @param null|int|string $server  Server context
     * @param null|int|string $app     Application context
     * @param EventContext    $context Event context payload
     * @param string          $channel Channel name (should be empty for events)
     *
     * @return string SHA256 hash for cooldown lookup
     */
    private function generateCooldownHash(
        string $type,
        int|string|null $server,
        int|string|null $app,
        EventContext $context,
        string $channel
    ): string {
        $payload = [
            'type' => $type,
            'server' => $server === null ? '' : (string) $server,
            'app' => $app === null ? '' : (string) $app,
            'context' => $context->toJson(),
            'channel' => $channel,
        ];

        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES);

        if ($encoded === false) {
            return '';
        }

        return hash('sha256', $encoded);
    }
}
