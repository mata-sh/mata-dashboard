<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring\Section;

use InvalidArgumentException;
use Mata\Config\ConfigValueValidator;

/** Metric thresholds and alarm rules keyed by operator-defined metric or event type. */
final readonly class ThresholdsConfig
{
    /**
     * @param array<string,array<string,float|int>> $serverData
     * @param array<string,array<string,float|int>> $appData
     * @param array<string,array<string,mixed>>     $alarmRules
     */
    public function __construct(
        public array $serverData,
        public array $appData,
        public array $alarmRules,
    ) {
        $reject = static function (string $message): void {
            throw new InvalidArgumentException($message);
        };
        self::thresholdMap($serverData, 'thresholds.server_data', $reject);
        self::thresholdMap($appData, 'thresholds.app_data', $reject);
        foreach ($alarmRules as $type => $rule) {
            self::validateAlarmRule($rule, 'thresholds.alarms.' . (string) $type);
        }
    }

    public static function empty(): self
    {
        return new self(serverData: [], appData: [], alarmRules: []);
    }

    /**
     * Invalid keyed entries are reported and skipped independently.
     *
     * @param array<string,mixed>   $raw
     * @param callable(string):void $reportInvalid
     */
    public static function fromArray(array $raw, callable $reportInvalid): self
    {
        $defaults = self::defaults()['alarms'];

        return new self(
            serverData: self::thresholdMap($raw['server_data'] ?? [], 'thresholds.server_data', $reportInvalid),
            appData: self::thresholdMap($raw['app_data'] ?? [], 'thresholds.app_data', $reportInvalid),
            alarmRules: self::alarmRuleMap($raw['alarms'] ?? [], $defaults, $reportInvalid),
        );
    }

    public static function default(): self
    {
        return self::fromArray(self::defaults(), static function (string $message): void {});
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'server_data' => $this->serverData,
            'app_data' => $this->appData,
            'alarms' => $this->alarmRules,
        ];
    }

    /** @return array<string,mixed> */
    public static function defaults(): array
    {
        return [
            'server_data' => [
                'cpu_usage' => ['info' => 50, 'warning' => 70, 'error' => 85, 'alarm' => 95],
                'disk_space_usage_percent' => ['info' => 60, 'warning' => 75, 'error' => 85, 'alarm' => 95],
                'total_system_filecount' => ['info' => 100000, 'warning' => 500000, 'error' => 1000000, 'alarm' => 2000000],
                'log_filesize_kb' => ['info' => 1000, 'warning' => 5000, 'error' => 10000, 'alarm' => 50000],
                'cronjobs_failed_count' => ['good' => -1, 'alarm' => 0],
                'database_connections_percent' => ['info' => 50, 'warning' => 60, 'error' => 70, 'alarm' => 80],
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
                'library_outdated_security' => ['alarm_threshold_event_count' => 1, 'alarm_threshold_window_minutes' => 1440],
                'cpu_usage_too_high' => ['alarm_threshold_event_count' => 2, 'alarm_threshold_window_minutes' => 30],
                'ram_usage_too_high' => ['alarm_threshold_event_count' => 2, 'alarm_threshold_window_minutes' => 30],
            ],
        ];
    }

    /**
     * @param callable(string):void $reportInvalid
     *
     * @return array<string,array<string,float|int>>
     */
    private static function thresholdMap(mixed $raw, string $path, callable $reportInvalid): array
    {
        if (!is_array($raw) || ($raw !== [] && array_is_list($raw))) {
            $reportInvalid("{$path} must be an object; ignoring section.");

            return [];
        }

        $validated = [];
        foreach ($raw as $metric => $thresholds) {
            $metric = (string) $metric;
            $metricPath = "{$path}.{$metric}";
            if (!is_array($thresholds) || ($thresholds !== [] && array_is_list($thresholds))) {
                $reportInvalid("{$metricPath} must be an object; skipping entry.");

                continue;
            }
            $values = [];
            foreach ($thresholds as $level => $value) {
                if ((!is_int($value) && !is_float($value)) || (is_float($value) && !is_finite($value))) {
                    $reportInvalid("{$metricPath}.{$level} must be a number; skipping entry.");
                    $values = [];

                    continue 2;
                }
                $values[(string) $level] = $value;
            }
            $validated[$metric] = $values;
        }

        return $validated;
    }

    /**
     * @param array<string,mixed>   $defaults
     * @param callable(string):void $reportInvalid
     *
     * @return array<string,array<string,mixed>>
     */
    private static function alarmRuleMap(mixed $raw, array $defaults, callable $reportInvalid): array
    {
        if (!is_array($raw) || ($raw !== [] && array_is_list($raw))) {
            $reportInvalid('thresholds.alarms must be an object; ignoring section.');

            return [];
        }

        $rules = [];
        foreach ($raw as $type => $rule) {
            $type = (string) $type;

            try {
                $rules[$type] = self::validateAlarmRule($rule, "thresholds.alarms.{$type}");
            } catch (InvalidArgumentException $exception) {
                $reportInvalid($exception->getMessage() . '; using the default rule when available.');
                if (isset($defaults[$type]) && is_array($defaults[$type])) {
                    $rules[$type] = $defaults[$type];
                }
            }
        }

        return $rules;
    }

    /** @return array<string,mixed> */
    private static function validateAlarmRule(mixed $rule, string $path): array
    {
        if (!is_array($rule) || ($rule !== [] && array_is_list($rule))) {
            throw new InvalidArgumentException("{$path} must be an object");
        }
        if (array_key_exists('enabled', $rule) && !is_bool($rule['enabled'])) {
            throw new InvalidArgumentException("{$path}.enabled must be a boolean");
        }

        $strategy = $rule['strategy'] ?? 'event_count_window';
        if (!is_string($strategy) || !in_array($strategy, ['event_count_window', 'consecutive_failures', 'immediate'], true)) {
            throw new InvalidArgumentException("{$path}.strategy is invalid");
        }
        if ($strategy === 'consecutive_failures' || array_key_exists('required_count', $rule)) {
            ConfigValueValidator::integer($rule['required_count'] ?? null, "{$path}.required_count", minimum: 1);
        }
        if ($strategy === 'consecutive_failures' || array_key_exists('window_minutes', $rule)) {
            ConfigValueValidator::integer($rule['window_minutes'] ?? null, "{$path}.window_minutes", minimum: 1);
        }
        if (array_key_exists('alarm_threshold_event_count', $rule)) {
            ConfigValueValidator::integer(
                $rule['alarm_threshold_event_count'],
                "{$path}.alarm_threshold_event_count",
                minimum: 0,
            );
        }
        if (array_key_exists('alarm_threshold_window_minutes', $rule)) {
            ConfigValueValidator::integer(
                $rule['alarm_threshold_window_minutes'],
                "{$path}.alarm_threshold_window_minutes",
                minimum: 1,
            );
        }

        return $rule;
    }
}
