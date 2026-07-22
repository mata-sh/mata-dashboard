<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring;

use Mata\Services\Alarm\AlarmDirectProcessor;
use Mata\Services\Alarm\Dto\Alarm;
use Mata\Services\Alarm\Dto\AlarmLevel;
use Mata\Services\Event\EventRecorderService;
use RuntimeException;

final class ThresholdsConfigLoader extends AbstractJsonConfigLoader implements MonitoringConfigLoaderInterface
{
    public function __construct(
        private EventRecorderService $eventRecorder,
        private ?AlarmDirectProcessor $alarmDirectProcessor = null,
        private bool $fallbackOnFailure = true,
    ) {}

    public function key(): string
    {
        return 'thresholds';
    }

    public function load(string $configRoot): array
    {
        return $this->loadThresholdsConfig(rtrim($configRoot, '/') . '/thresholds.json');
    }

    /** @return array<string,mixed> */
    public function loadThresholdsConfig(string $filePath): array
    {
        try {
            $thresholds = $this->normalizeJson($this->readJsonObject($filePath));
        } catch (RuntimeException $exception) {
            $this->reportLoadFailure($filePath, $exception);

            throw new MonitoringConfigLoadException('thresholds.json invalid or empty', previous: $exception);
        }
        if ($thresholds === []) {
            $exception = new RuntimeException('thresholds.json invalid or empty');
            $this->reportLoadFailure($filePath, $exception);

            throw new MonitoringConfigLoadException('thresholds.json invalid or empty', previous: $exception);
        }

        return $this->normalizeAlarmRules($thresholds);
    }

    /** @return array<string,mixed> */
    public function getDefaultConfig(): array
    {
        return [
            'server_data' => [
                'cpu_usage' => ['info' => 50, 'warning' => 70, 'error' => 85, 'alarm' => 95],
                'disk_space_usage_percent' => ['info' => 60, 'warning' => 75, 'error' => 85, 'alarm' => 95],
                'total_system_filecount' => ['info' => 100000, 'warning' => 500000, 'error' => 1000000, 'alarm' => 2000000],
                'log_filesize_kb' => ['info' => 1000, 'warning' => 5000, 'error' => 10000, 'alarm' => 50000],
                'cronjobs_failed_count' => ['good' => -1, 'alarm' => 0],
            ],
            'app_data' => [
                'session_count' => ['info' => 50, 'warning' => 100, 'error' => 200, 'alarm' => 500],
                'error_count' => ['info' => 1, 'warning' => 5, 'error' => 10, 'alarm' => 25],
            ],
            'alarms' => [
                'app_unreachable' => ['strategy' => 'consecutive_failures', 'required_count' => 2, 'window_minutes' => 10],
                'server_apps_transport_unreachable' => ['strategy' => 'immediate'],
                'server_metrics.ingestion_run_empty' => ['alarm_threshold_event_count' => 3, 'alarm_threshold_window_minutes' => 30],
                'server_metrics.ingestion_stalled' => ['alarm_threshold_event_count' => 1, 'alarm_threshold_window_minutes' => 60],
                'fatal_errors_occurring' => ['alarm_threshold_event_count' => 1, 'alarm_threshold_window_minutes' => 1440],
                'logfile_too_large' => ['alarm_threshold_event_count' => 1, 'alarm_threshold_window_minutes' => 1440],
                'cpu_usage_too_high' => ['alarm_threshold_event_count' => 2, 'alarm_threshold_window_minutes' => 30],
                'ram_usage_too_high' => ['alarm_threshold_event_count' => 2, 'alarm_threshold_window_minutes' => 30],
            ],
        ];
    }

    public function shouldFallback(): bool
    {
        return $this->fallbackOnFailure;
    }

    /** @param array<string,mixed> $thresholds @return array<string,mixed> */
    private function normalizeAlarmRules(array $thresholds): array
    {
        $rules = $thresholds['alarms'] ?? [];
        if (!is_array($rules)) {
            $this->recordConfigurationEvent('thresholds_config_validation_error', 'Alarm rules must be an object.', 'warning');
            $thresholds['alarms'] = [];

            return $thresholds;
        }

        $defaults = $this->getDefaultConfig()['alarms'];
        foreach ($rules as $type => $rule) {
            if ($this->isValidAlarmRule($rule)) {
                continue;
            }
            $type = (string) $type;
            $this->recordConfigurationEvent(
                'thresholds_config_validation_error',
                "Invalid alarm rule for {$type}; using the default rule when available.",
                'warning',
            );
            if (isset($defaults[$type])) {
                $rules[$type] = $defaults[$type];
            } else {
                unset($rules[$type]);
            }
        }
        $thresholds['alarms'] = $rules;

        return $thresholds;
    }

    private function isValidAlarmRule(mixed $rule): bool
    {
        if (!is_array($rule) || (array_key_exists('enabled', $rule) && !is_bool($rule['enabled']))) {
            return false;
        }
        if (($rule['enabled'] ?? true) === false) {
            return true;
        }
        $strategy = $rule['strategy'] ?? 'event_count_window';
        if (!is_string($strategy) || !in_array($strategy, ['event_count_window', 'consecutive_failures', 'immediate'], true)) {
            return false;
        }
        if ($strategy !== 'consecutive_failures') {
            return true;
        }

        return is_numeric($rule['required_count'] ?? null)
            && (int) $rule['required_count'] >= 1
            && is_numeric($rule['window_minutes'] ?? null)
            && (int) $rule['window_minutes'] >= 1;
    }

    private function reportLoadFailure(string $filePath, RuntimeException $exception): void
    {
        $this->recordConfigurationEvent(
            type: 'thresholds_config_not_found',
            message: "Could not load {$filePath} -> no Server Watching (JSON error: {$exception->getMessage()})",
            level: 'critical',
            forceAlarm: true,
        );
    }

    private function recordConfigurationEvent(string $type, string $message, string $level = 'info', bool $forceAlarm = false): void
    {
        $recordResult = $this->eventRecorder->recordEvent(type: $type, message: $message, level: $level, forceAlarm: $forceAlarm);
        if ($forceAlarm && $this->alarmDirectProcessor !== null) {
            $this->alarmDirectProcessor->process(new Alarm(
                type: $type,
                message: $message,
                level: AlarmLevel::fromString($level),
                server: $recordResult->serverLabel,
                app: $recordResult->appLabel,
                channels: ['email'],
            ), $recordResult);
        }
    }
}
