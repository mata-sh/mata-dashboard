<?php

declare(strict_types=1);

namespace Mata\Services\Alarm;

use Mata\Config\Dto\ApplicationConfig;
use Mata\Services\Alarm\Dto\AlarmRule;
use Mata\Services\Alarm\Dto\AlarmThresholdEvaluation;
use Mata\Services\Event\CooldownHashFactory;
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
 * - **AlarmThresholdCooldownService** (this): Threshold checks and cooldown management
 * - **AlarmDecisionProcessor**: Policy decisions and alarm creation
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
class AlarmThresholdCooldownService
{
    /** @var array<string,AlarmRule> Alarm rules keyed by type */
    private array $alarmRules = [];

    /** @var array<string,int> Alarm type to interval minutes mapping */
    private array $alarmIntervals = [];

    private EventAggregatorServiceInterface $aggregator;
    private EventRecorderService $recorder;
    private ApplicationConfig $appConfig;
    private CooldownHashFactory $cooldownHashFactory;

    /**
     * Initialize alarm evaluation service.
     *
     * @param EventAggregatorServiceInterface $aggregator          Event querying service for threshold checks
     * @param EventRecorderService            $recorder            Event recorder for cooldown tracking
     * @param ApplicationConfig               $appConfig           Application configuration for alarm settings
     * @param null|CooldownHashFactory        $cooldownHashFactory Optional cooldown hash factory
     */
    public function __construct(
        EventAggregatorServiceInterface $aggregator,
        EventRecorderService $recorder,
        ApplicationConfig $appConfig,
        ?CooldownHashFactory $cooldownHashFactory = null
    ) {
        $this->aggregator = $aggregator;
        $this->recorder = $recorder;
        $this->appConfig = $appConfig;
        $this->cooldownHashFactory = $cooldownHashFactory ?? new CooldownHashFactory();
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

        $rules = is_array($thresholdsConfig['alarms'] ?? null) ? $thresholdsConfig['alarms'] : [];
        $this->alarmRules = [];
        foreach ($rules as $type => $rule) {
            $this->alarmRules[(string) $type] = is_array($rule)
                ? AlarmRule::fromArray($rule)
                : AlarmRule::invalid();
        }
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
        int|string|null $app,
        ?EventContext $context = null,
    ): bool {
        return $this->evaluateAlarmForEvent($type, $server, $app, $context)->passed;
    }

    /**
     * Evaluate a rule and return the pass/fail result alongside immutable
     * evidence for alarm persistence and rendering.
     */
    public function evaluateAlarmForEvent(
        string $type,
        int|string|null $server,
        int|string|null $app,
        ?EventContext $context = null,
    ): AlarmThresholdEvaluation {
        if (!$this->appConfig->general->alarmsEnabled) {
            return $this->disabledEvaluation();
        }

        $rule = $this->alarmRules[$type] ?? AlarmRule::immediate();
        if (!$rule->enabled) {
            return $this->disabledEvaluation('alarm disabled by configuration');
        }

        return match ($rule->strategy) {
            AlarmRule::STRATEGY_CONSECUTIVE_FAILURES => $this->evaluateConsecutiveFailures($rule, $context),
            AlarmRule::STRATEGY_EVENT_COUNT_WINDOW => $this->evaluateEventCountWindow($type, $server, $app, $rule),
            AlarmRule::STRATEGY_IMMEDIATE => $this->immediateEvaluation(),
            default => $this->invalidEvaluation('invalid alarm strategy'),
        };
    }

    private function evaluateConsecutiveFailures(AlarmRule $rule, ?EventContext $context): AlarmThresholdEvaluation
    {
        $required = $rule->requiredCount;
        $window = $rule->windowMinutes;
        $data = $context?->toArray()['additional_data'] ?? [];
        $observed = is_numeric($data['consecutive_failures'] ?? null) ? (int) $data['consecutive_failures'] : 0;
        $first = isset($data['first_failure_at']) ? strtotime((string) $data['first_failure_at']) : false;
        $withinWindow = $first !== false && $first >= time() - ($window * 60);

        return new AlarmThresholdEvaluation($observed >= $required && $withinWindow, [
            'strategy' => AlarmRule::STRATEGY_CONSECUTIVE_FAILURES,
            'required_count' => $required,
            'observed_count' => $observed,
            'window_minutes' => $window,
            'label' => "{$required} consecutive failed cycles within {$window} minutes",
        ]);
    }

    private function evaluateEventCountWindow(
        string $type,
        int|string|null $server,
        int|string|null $app,
        AlarmRule $rule,
    ): AlarmThresholdEvaluation {
        $required = $rule->requiredCount;
        $window = $rule->windowMinutes;
        $recent = $this->aggregator->getLastEventsByContext($type, null, $server, $app, $window);
        $observed = count($recent);

        return new AlarmThresholdEvaluation($observed >= $required, [
            'strategy' => AlarmRule::STRATEGY_EVENT_COUNT_WINDOW,
            'required_count' => $required,
            'observed_count' => $observed,
            'window_minutes' => $window,
            'label' => $required === 1 ? 'first event' : "{$required} events within {$window} minutes",
        ]);
    }

    private function disabledEvaluation(string $label = 'alarms disabled'): AlarmThresholdEvaluation
    {
        return new AlarmThresholdEvaluation(false, ['strategy' => AlarmRule::STRATEGY_DISABLED, 'label' => $label]);
    }

    private function immediateEvaluation(): AlarmThresholdEvaluation
    {
        return new AlarmThresholdEvaluation(true, [
            'strategy' => AlarmRule::STRATEGY_IMMEDIATE,
            'required_count' => 1,
            'observed_count' => 1,
            'label' => 'first event',
        ]);
    }

    private function invalidEvaluation(string $label): AlarmThresholdEvaluation
    {
        return new AlarmThresholdEvaluation(false, ['strategy' => AlarmRule::STRATEGY_INVALID, 'label' => $label]);
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
     * Hash format: SHA256(type|server|app|context|channel). Threshold configuration changes
     * alter hashes, causing one accepted extra alarm after a configuration edit.
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
        return $this->cooldownHashFactory->forAlarmChannel(
            type: $type,
            server: $server,
            app: $app,
            context: $context,
            channel: $channel
        );
    }
}
