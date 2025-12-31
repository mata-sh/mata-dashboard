<?php

declare(strict_types=1);

namespace Watcher\Services\Foundation;

use PDOException;
use Watcher\Config\Dto\ApplicationConfig;
use Watcher\Models\Dto\EventRecordResult;
use Watcher\Models\EventRecorder;
use Watcher\Services\Foundation\Dto\Alert;
use Watcher\Services\Foundation\Dto\AlertLevel;
use Watcher\Services\Foundation\Dto\EventContext;

/**
 * Monitoring event and alert management service.
 *
 * Handles monitoring alerts and notifications:
 * - Threshold monitoring and evaluation
 * - Alert generation and notification dispatch
 * - Alert state management and persistence
 * - Email/SMS notification delivery
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

    private EventRecorder $eventRecorder;
    private AlertManager $alertManager;
    private ApplicationConfig $appConfig;

    /**
     * Initialize event manager with threshold and interval configuration.
     *
     * @param EventRecorder       $eventRecorder    Event recorder for monitoring events and alarm management
     * @param AlertManager        $alertManager     Alert delivery orchestrator
     * @param ApplicationConfig   $appConfig        Application configuration for alarm/event settings
     * @param array<string,mixed> $thresholdsConfig Complete thresholds configuration
     * @param array<string,mixed> $intervalsConfig  Interval configuration for events and alarms
     */
    public function __construct(
        EventRecorder $eventRecorder,
        AlertManager $alertManager,
        ApplicationConfig $appConfig,
        array $thresholdsConfig = [],
        array $intervalsConfig = []
    ) {
        $this->serverDataThresholds = $thresholdsConfig['server_data'] ?? [];
        $this->appDataThresholds = $thresholdsConfig['app_data'] ?? [];
        $this->alarmThresholds = $thresholdsConfig['alarms'] ?? [];
        $this->eventIntervals = $intervalsConfig['events']['min_event_interval_minutes'] ?? [];
        $this->alarmIntervals = $intervalsConfig['alarms']['min_alarm_interval_minutes'] ?? [];
        $this->eventRecorder = $eventRecorder;
        $this->alertManager = $alertManager;
        $this->appConfig = $appConfig;
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

        return !$this->eventRecorder->hasActiveCooldown($cooldownHash);
    }

    /**
     * Get latest monitoring events from event recorder.
     *
     * Retrieves recent monitoring events for display in the dashboard.
     * Events represent various monitoring activities and status updates.
     *
     * @return array<int,array<string,mixed>> Array of recent monitoring events with metadata
     */
    public function getLatestEvents(): array
    {
        return $this->eventRecorder->getLatestEvents();
    }

    /**
     * Get latest monitoring alarms from event recorder.
     *
     * Retrieves recent monitoring alarms that triggered email notifications.
     * Alarms represent critical events that require immediate attention.
     *
     * @return array<int,array<string,mixed>> Array of recent monitoring alarms with metadata
     */
    public function getLatestAlarms(): array
    {
        return $this->eventRecorder->getLatestAlarms();
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

        try {
            $eventRecordResult = $this->eventRecorder->recordEvent(
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
                    $this->eventRecorder->upsertAlarmCooldown($alarmHash, $alarmCooldownMinutes);
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
            $this->alertManager->handleAlert($alert, $eventRecordResult);
        }

        return [
            'eventRecorded' => $eventRecorded,
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
        $recentEvents = $this->eventRecorder->getLastEventsByContext(
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
        $activeCooldowns = $this->eventRecorder->getActiveAlarmCooldowns(array_values($channelHashes));
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
