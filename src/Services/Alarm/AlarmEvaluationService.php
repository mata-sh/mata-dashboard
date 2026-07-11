<?php

declare(strict_types=1);

namespace Mata\Services\Alarm;

use Mata\Config\Dto\ApplicationConfig;
use Mata\Services\Event\Dto\EventContext;
use Mata\Services\Event\EventAggregatorServiceInterface;
use Mata\Services\Event\EventRecorderService;

/**
 * Alarm evaluation service for alarm decision logic.
 *
 * Responsible for:
 * - Determining if events should trigger alarms (threshold evaluation)
 * - Filtering channels based on cooldown status
 * - Managing alarm cooldown intervals
 * - Generating cooldown hashes for alarm deduplication
 *
 * ## Architecture:
 * - **AlarmEvaluationService** (this): Alarm decision logic, threshold checks, cooldown management
 * - **AlarmManager**: Alarm delivery creation and priority resolution
 * - **EventManager**: Event creation and event-specific logic
 *
 * ## Usage in cronjobs:
 * ```php
 * // Check if event should trigger alarm
 * $shouldAlarm = $alarmEvaluator->shouldTriggerAlarmForEvent(
 *     type: $event['type'],
 *     server: $event['server_id'],
 *     app: $event['application_id']
 * );
 *
 * // Filter channels not on cooldown
 * $readyChannels = $alarmEvaluator->filterReadyChannels(
 *     type: $event['type'],
 *     requestedChannels: ['email', 'sms'],
 *     server: $event['server_id'],
 *     app: $event['application_id'],
 *     context: $eventContext
 * );
 * ```
 */
class AlarmEvaluationService
{
    /** @var array<string,mixed> Alarm threshold configuration */
    private array $alarmThresholds = [];

    /** @var array<string,int> Alarm type to interval minutes mapping */
    private array $alarmIntervals = [];

    private EventAggregatorServiceInterface $aggregator;
    private EventRecorderService $recorder;
    private ApplicationConfig $appConfig;

    /**
     * Initialize alarm evaluation service.
     *
     * @param EventAggregatorServiceInterface $aggregator Event querying service for threshold checks
     * @param EventRecorderService            $recorder   Event recorder for cooldown tracking
     * @param ApplicationConfig               $appConfig  Application configuration for alarm settings
     */
    public function __construct(
        EventAggregatorServiceInterface $aggregator,
        EventRecorderService $recorder,
        ApplicationConfig $appConfig
    ) {
        $this->aggregator = $aggregator;
        $this->recorder = $recorder;
        $this->appConfig = $appConfig;
    }

    /**
     * Set monitoring settings from loaded configuration.
     *
     * Updates alarm thresholds and intervals from monitoring config files.
     *
     * @param array<string,mixed> $settings Monitoring settings containing 'thresholds' and 'intervals' keys
     *
     * @return $this For method chaining
     */
    public function setMonitoringSettings(array $settings): self
    {
        $thresholdsConfig = $settings['thresholds'] ?? [];
        $intervalsConfig = $settings['intervals'] ?? [];

        $this->alarmThresholds = $thresholdsConfig['alarms'] ?? [];
        $this->alarmIntervals = $intervalsConfig['alarms']['min_alarm_interval_minutes'] ?? [];

        return $this;
    }

    /**
     * Determine if an event should trigger an alarm based on threshold configuration.
     *
     * Evaluates alarm readiness by checking:
     * - Global alarm enablement
     * - Event count thresholds (if configured for this event type)
     *
     * @param string          $type   Event type identifier
     * @param null|int|string $server Server context
     * @param null|int|string $app    Application context
     *
     * @return bool True if alarm should be triggered
     */
    public function shouldTriggerAlarmForEvent(
        string $type,
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
     * Filter channels to only those ready to receive alarms (not on cooldown).
     *
     * Performs per-channel cooldown checking and returns only available channels.
     *
     * @param string          $type              Alarm type identifier
     * @param array<string>   $requestedChannels Channels to check (e.g., ['email', 'sms'])
     * @param null|int|string $server            Server context
     * @param null|int|string $app               Application context
     * @param EventContext    $context           Alarm context payload
     *
     * @return array<string> Filtered array of ready (non-cooled-down) channels
     */
    public function filterReadyChannels(
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
     * Get alarm cooldown interval in minutes for a given alarm type.
     *
     * Resolution order:
     * 1. Alarm-specific configuration from intervals.json
     * 2. User-configured default from intervals.json '_default' key
     * 3. Hardcoded fallback (1440 minutes / 24 hours)
     *
     * @param string $type Alarm type identifier
     *
     * @return int Cooldown interval in minutes
     */
    public function getAlarmIntervalMinutes(string $type): int
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
     * Generate cooldown hash for alarm deduplication.
     *
     * Creates a unique hash for cooldown tracking based on alarm attributes.
     * Hash format: SHA256(type|server|app|context|channel)
     *
     * @param string          $type    Alarm type identifier
     * @param null|int|string $server  Server context
     * @param null|int|string $app     Application context
     * @param EventContext    $context Alarm context payload
     * @param string          $channel Channel name (e.g., 'email', 'sms')
     *
     * @return string SHA256 hash for cooldown lookup
     */
    public function generateCooldownHash(
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
}
