<?php

declare(strict_types=1);

namespace Watcher\Services\Foundation;

use LogicException;
use PDOException;
use Watcher\Config\Dto\ApplicationConfig;
use Watcher\Models\Dto\EventRecordResult;
use Watcher\Services\Foundation\Dto\Alert;
use Watcher\Services\Foundation\Dto\AlertLevel;
use Watcher\Services\Foundation\Dto\EventContext;

/**
 * Monitoring event and alert management service.
 *
 * PRIMARY FACADE for event operations - use this in most cases.
 *
 * ## Architecture & Usage Patterns
 *
 * EventManager is the main entry point providing:
 * - Business logic (threshold evaluation, alarm policies, cooldown management)
 * - Orchestrated writes via handleEvent() with full policy evaluation
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
 *     public function __construct(private EventAggregatorService $aggregator) {}
 * }
 * ```
 *
 * ### Component Responsibilities:
 * - **EventManager** (this): Business logic facade, threshold evaluation, policy orchestration
 * - **EventRecorderService**: Low-level event/alarm persistence and cooldown tracking
 * - **EventAggregatorService**: Event/alarm querying, reporting, cleanup operations
 *
 * @see EventRecorderService For direct event persistence (rarely used directly)
 * @see EventAggregatorService For read-only event querying
 */
class EventManager
{
    /**
     * Default cooldown interval for unconfigured event types (in minutes)
     * Prevents spam for event types without specific configuration.
     */
    private const DEFAULT_EVENT_INTERVAL_MINUTES = 30;

    /** @var array<string,mixed> */
    private array $serverDataThresholds = [];

    /** @var array<string,mixed> */
    private array $appDataThresholds = [];

    /** @var array<string,mixed> */
    private array $alarmThresholds = [];

    /** @var array<string,int> Event type to interval minutes mapping */
    private array $eventIntervals = [];

    /** @var array<string,int> Alarm type to interval minutes mapping */
    private array $alarmIntervals = [];

    /**
     * Event recorder for persistence and cooldown tracking.
     * Public property for direct access when needed (rare - prefer handleEvent).
     */
    public ?EventRecorderService $recorder = null;

    /**
     * Event aggregator for querying and reporting.
     * Public property for direct read access (common usage pattern).
     */
    public ?EventAggregatorService $aggregator = null;

    private AlertManager $alertManager;
    private ApplicationConfig $appConfig;

    /**
     * Initialize event manager with optional dependencies.
     *
     * Supports fluent initialization via setter methods for deferred dependency injection.
     * This allows EventManager to be created before loading monitoring settings.
     *
     * Use setMonitoringSettings() to configure thresholds and intervals after initialization.
     *
     * @param AlertManager                $alertManager    Alert delivery orchestrator
     * @param ApplicationConfig           $appConfig       Application configuration for alarm/event settings
     * @param null|EventRecorderService   $eventRecorder   Optional event recorder (can be set later via setEventRecorder)
     * @param null|EventAggregatorService $eventAggregator Optional event aggregator (can be set later via setEventAggregator)
     */
    public function __construct(
        AlertManager $alertManager,
        ApplicationConfig $appConfig,
        ?EventRecorderService $eventRecorder = null,
        ?EventAggregatorService $eventAggregator = null
    ) {
        $this->alertManager = $alertManager;
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
     * @param EventAggregatorService $aggregator Event aggregator instance
     *
     * @return $this For method chaining
     */
    public function setEventAggregator(EventAggregatorService $aggregator): self
    {
        $this->aggregator = $aggregator;

        return $this;
    }

    /**
     * Set monitoring settings (fluent interface).
     *
     * Updates thresholds and intervals from loaded monitoring configuration.
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
        $this->alarmThresholds = $thresholdsConfig['alarms'] ?? [];
        $this->eventIntervals = $intervalsConfig['events']['min_event_interval_minutes'] ?? [];
        $this->alarmIntervals = $intervalsConfig['alarms']['min_alarm_interval_minutes'] ?? [];

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
     * Get alarm configuration for a specific event type.
     *
     * @param string $type Event type for threshold lookup
     *
     * @return null|array<string,mixed> Alarm configuration or null if not found
     */
    public function getAlarmConfig(string $type): ?array
    {
        return $this->alarmThresholds[$type] ?? null;
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
     * Evaluate policy, send alerts, and persist events in a single call.
     *
     * @param string                  $type     Event type identifier
     * @param string                  $message  Human readable message
     * @param array<int|string,mixed> $context  Additional context payload
     * @param string                  $level    Severity level
     * @param null|int|string         $server   Server context (name or ID)
     * @param null|int|string         $app      Application context (code or ID)
     * @param array<string>           $channels Notification channels (e.g., email, SMS)
     *
     * @return array{eventRecorded: bool, alarmSent: bool, eventId:?int, alarmId:?int}
     */
    public function handleEvent(
        string $type,
        string $message,
        array $context = [],
        string $level = 'info',
        int|string|null $server = null,
        int|string|null $app = null,
        array $channels = ['email']
    ): array {
        $eventContext = EventContext::fromArray($context);

        $cooldownMinutes = $this->getEventIntervalMinutes($type);
        $cooldownHash = $this->generateCooldownHash($type, $server, $app, $eventContext, '');

        $shouldCreateEvent = $this->shouldCreateEvent(
            cooldownHash: $cooldownHash,
            cooldownMinutes: $cooldownMinutes
        );

        $isAlarmReady = $this->isAlarmReady($type, $message, $server, $app);

        $readyChannels = [];
        if ($isAlarmReady) {
            $readyChannels = $this->getReadyChannels(
                type: $type,
                requestedChannels: $channels,
                server: $server,
                app: $app,
                context: $eventContext
            );
        }

        $shouldTriggerAlarm = !empty($readyChannels);

        if (!$shouldCreateEvent && !$shouldTriggerAlarm) {
            return [
                'eventRecorded' => false,
                'alarmSent' => false,
                'eventId' => null,
                'alarmId' => null,
            ];
        }

        $contextPayload = $eventContext->toJson();

        $fallbackServer = is_scalar($server) ? (string) $server : null;
        $fallbackApp = is_scalar($app) ? (string) $app : null;
        $eventRecordResult = new EventRecordResult(
            eventId: 0,
            alarmId: null,
            serverId: null,
            appId: null,
            serverLabel: $fallbackServer,
            appLabel: $fallbackApp,
            serverKey: $fallbackServer
        );
        $eventRecorded = false;

        if ($this->recorder === null) {
            throw new LogicException(
                'EventRecorder not initialized. Call setEventRecorder() before using handleEvent().'
            );
        }

        try {
            $eventRecordResult = $this->recorder->recordEvent(
                type: $type,
                message: $message,
                context: $contextPayload,
                level: $level,
                server: $server,
                app: $app,
                channels: $channels,
                isAlarm: $shouldTriggerAlarm,
                cooldownHash: $cooldownHash,
                cooldownMinutes: $cooldownMinutes
            );
            $eventRecorded = $eventRecordResult->wasRecorded();
        } catch (PDOException $exception) {
            // Persistence unavailable; rely on filesystem fallback in AlertManager.
        }

        if ($shouldTriggerAlarm) {
            // Set cooldowns for all channels we're about to alert
            $alarmCooldownMinutes = $this->getAlarmIntervalMinutes($type);
            foreach ($readyChannels as $channel) {
                $alarmHash = $this->generateCooldownHash($type, $server, $app, $eventContext, $channel);

                try {
                    $this->recorder->upsertAlarmCooldown($alarmHash, $alarmCooldownMinutes);
                } catch (PDOException) {
                    // Ignore - database unavailable, cooldowns will be re-established when DB returns.
                }
            }

            // Send alert only to ready channels
            $alert = new Alert(
                type: $type,
                message: $message,
                context: $contextPayload ?: null,
                level: AlertLevel::fromString($level),
                server: $eventRecordResult->serverLabel,
                app: $eventRecordResult->appLabel,
                channels: $readyChannels  // Only non-cooled-down channels
            );
            // todo: might throw an excepitotn just like the other outcome methods
            $this->alertManager->handleAlert($alert, $eventRecordResult);
        }

        return [
            'eventRecorded' => $eventRecorded,
            // todo: shouldtrigger alarm is not the same as alarm sent
            'alarmSent' => $shouldTriggerAlarm,
            'eventId' => $eventRecordResult->eventId > 0 ? $eventRecordResult->eventId : null,
            'alarmId' => null,
        ];
    }

    /**
     * Check if alarm is ready to be triggered based on global settings and thresholds.
     *
     * First stage of alarm determination - checks:
     * - Global alarm enablement
     * - Event count thresholds (if configured)
     *
     * @param string          $type    Alarm type identifier
     * @param string          $message Alarm message
     * @param null|int|string $server  Server context
     * @param null|int|string $app     Application context
     *
     * @return bool True if alarm should proceed to channel evaluation
     */
    private function isAlarmReady(
        string $type,
        string $message,
        int|string|null $server,
        int|string|null $app
    ): bool {
        if (!$this->appConfig->general->alarmsEnabled) {
            return false;
        }

        $config = $this->alarmThresholds[$type] ?? null;
        if (!$config) {
            return true;  // No config = always evaluate
        }

        $eventCountThreshold = (int) ($config['alarm_threshold_event_count'] ?? 1);

        // If threshold is 0 or negative, always trigger
        if ($eventCountThreshold <= 0) {
            return true;
        }

        $minEventInterval = (int) ($config['min_event_interval_minutes'] ?? 720);

        // Check recent events to see if threshold met
        if ($this->aggregator === null) {
            throw new LogicException(
                'EventAggregator not initialized. Call setEventAggregator() before using handleEvent() with alarm thresholds.'
            );
        }

        $recentEvents = $this->aggregator->getLastEventsByContext(
            type: $type,
            context: null,
            server: $server,
            app: $app,
            maxAgeMinutes: $minEventInterval
        );

        return count($recentEvents) >= $eventCountThreshold;
    }

    /**
     * Get channels that are ready to receive alarms (not on cooldown).
     *
     * Second stage of alarm determination - checks per-channel cooldowns.
     * Returns only channels that are not currently on cooldown.
     *
     * @param string          $type              Alarm type identifier
     * @param array<string>   $requestedChannels Channels to check (e.g., ['email', 'sms'])
     * @param null|int|string $server            Server context
     * @param null|int|string $app               Application context
     * @param EventContext    $context           Alarm context payload
     *
     * @return array<string> Filtered array of ready (non-cooled-down) channels
     */
    private function getReadyChannels(
        string $type,
        array $requestedChannels,
        int|string|null $server,
        int|string|null $app,
        EventContext $context
    ): array {
        if (empty($requestedChannels)) {
            return [];
        }

        $cooldownMinutes = $this->getAlarmIntervalMinutes($type);

        // If no cooldown configured, all channels available
        if ($cooldownMinutes <= 0) {
            return $requestedChannels;
        }

        // Build hashes for all requested channels
        $channelHashes = [];
        foreach ($requestedChannels as $channel) {
            $hash = $this->generateCooldownHash($type, $server, $app, $context, $channel);
            $channelHashes[$channel] = $hash;
        }

        // Batch check which hashes are on cooldown
        if ($this->recorder === null) {
            return $requestedChannels; // No recorder available, all channels available
        }

        $activeCooldowns = $this->recorder->getActiveAlarmCooldowns(array_values($channelHashes));
        $activeCooldownSet = array_flip($activeCooldowns);

        // Filter out channels with active cooldowns
        $availableChannels = [];
        foreach ($requestedChannels as $channel) {
            if (!isset($activeCooldownSet[$channelHashes[$channel]])) {
                $availableChannels[] = $channel;
            }
        }

        return $availableChannels;
    }

    /**
     * Get event cooldown interval in minutes for a given event type.
     *
     * Resolution order:
     * 1. Event-specific configuration from intervals.json
     * 2. Alarm-specific configuration from thresholds.json (backward compatibility)
     * 3. User-configured default from intervals.json '_default' key
     * 4. Hardcoded fallback (30 minutes) to prevent spam
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
     * Get alarm cooldown interval in minutes for a given alarm type.
     *
     * Resolution order:
     * 1. Alarm-specific configuration from intervals.json
     * 2. Thresholds config (backward compatibility)
     * 3. User-configured default from intervals.json '_default' key
     * 4. Hardcoded fallback (1440 minutes / 24 hours)
     *
     * @param string $type Alarm type identifier
     *
     * @return int Cooldown interval in minutes
     */
    private function getAlarmIntervalMinutes(string $type): int
    {
        // 1. Check alarm-specific configuration from intervals.json
        if (isset($this->alarmIntervals[$type])) {
            return max(0, (int) $this->alarmIntervals[$type]);
        }

        // 2. Check default from intervals.json
        if (isset($this->alarmIntervals['_default'])) {
            return max(0, (int) $this->alarmIntervals['_default']);
        }

        // 3. Hardcoded fallback - 24 hours
        return 1440;
    }

    /**
     * Generate cooldown hash for deduplication.
     *
     * Creates a unique hash for cooldown tracking based on event/alarm attributes.
     * Events use empty channel string, alarms include the specific channel name.
     * Hash format: SHA256(type|server|app|context|channel)
     *
     * @param string          $type    Event/alarm type identifier
     * @param null|int|string $server  Server context
     * @param null|int|string $app     Application context
     * @param EventContext    $context Event/alarm context payload
     * @param string          $channel Channel name (empty for events, 'email'/'sms'/etc for alarms)
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
