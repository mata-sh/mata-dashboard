<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring\Section;

use Mata\Services\ServerMetrics\MetricCatalog;
use Mata\Services\ServerMetrics\MetricQueryPolicy;

/** Validated dashboard chart defaults, allowlists, and display metadata. */
final readonly class DashboardChartConfig
{
    /** @var array<string,string> */
    private const DEFAULT_METRIC_LABELS = [
        'cpu' => 'CPU Usage (%)',
        'ram_percent' => 'RAM Usage (%)',
        'ram_used' => 'RAM Used',
        'disk_percent' => 'Disk Usage (%)',
        'disk_used' => 'Disk Used',
        'file_count' => 'File Count',
        'process_count' => 'Process Count',
    ];

    /** @var array<string,array{label:string,range:string,granularity:string,refresh:int}> */
    private const DEFAULT_TIMESPANS = [
        '24h' => ['label' => '24 Hours', 'range' => '24h', 'granularity' => 'auto', 'refresh' => 60],
        '7d' => ['label' => '7 Days', 'range' => '7d', 'granularity' => 'auto', 'refresh' => 300],
    ];

    /**
     * @param array<string,array{key:string,label:string}>                                             $metrics
     * @param array<string,array{key:string,label:string,range:string,granularity:string,refresh:int}> $timespans
     */
    private function __construct(
        public array $metrics,
        public array $timespans,
        public string $defaultMetric,
        public string $defaultTimespan,
    ) {}

    public static function default(MetricQueryPolicy $queryPolicy): self
    {
        return new self(
            metrics: self::defaultMetrics(),
            timespans: self::defaultTimespans($queryPolicy),
            defaultMetric: 'cpu',
            defaultTimespan: '24h',
        );
    }

    /**
     * @param array<string,mixed>   $raw
     * @param callable(string):void $reportInvalid
     */
    public static function fromArray(
        array $raw,
        MetricCatalog $catalog,
        MetricQueryPolicy $queryPolicy,
        callable $reportInvalid,
    ): self {
        $metrics = null;
        $timespans = null;
        $defaultMetric = null;
        $defaultTimespan = null;

        foreach ($raw as $key => $value) {
            $key = (string) $key;
            if ($key === '_comment') {
                continue;
            }

            if ($key === 'metrics') {
                if (!is_array($value)) {
                    $reportInvalid("charts.json dashboard 'metrics' must be an object or list -> ignored");
                } else {
                    $metrics = self::normalizeMetrics($value, $catalog, $queryPolicy, $reportInvalid);
                }

                continue;
            }

            if ($key === 'timespans') {
                if (!is_array($value)) {
                    $reportInvalid("charts.json dashboard 'timespans' must be an object or list -> ignored");
                } else {
                    $timespans = self::normalizeTimespans($value, $catalog, $queryPolicy, $reportInvalid);
                }

                continue;
            }

            if ($key === 'default_metric') {
                if (!is_string($value)) {
                    $reportInvalid("charts.json dashboard 'default_metric' must be a string -> ignored");

                    continue;
                }

                $normalized = $queryPolicy->normalizeMetric($value);
                if ($normalized === '') {
                    $reportInvalid("charts.json dashboard 'default_metric' must not be empty -> ignored");
                } elseif (!$catalog->supports($normalized)) {
                    $reportInvalid("charts.json dashboard references unknown default metric '{$normalized}' -> ignored");
                } else {
                    $defaultMetric = $normalized;
                }

                continue;
            }

            if ($key === 'default_timespan') {
                if (!is_string($value)) {
                    $reportInvalid("charts.json dashboard 'default_timespan' must be a string -> ignored");

                    continue;
                }

                $normalized = strtolower(trim($value));
                if ($normalized === '') {
                    $reportInvalid("charts.json dashboard 'default_timespan' must not be empty -> ignored");
                } else {
                    $defaultTimespan = $normalized;
                }

                continue;
            }

            $reportInvalid("charts.json dashboard contains unknown key '{$key}' -> ignored");
        }

        $metrics = $metrics === null || $metrics === [] ? self::defaultMetrics() : $metrics;
        $timespans = $timespans === null || $timespans === [] ? self::defaultTimespans($queryPolicy) : $timespans;
        if ($defaultMetric === null || !isset($metrics[$defaultMetric])) {
            $defaultMetric = array_key_first($metrics) ?? 'cpu';
        }
        if ($defaultTimespan === null || !isset($timespans[$defaultTimespan])) {
            $defaultTimespan = array_key_first($timespans) ?? '24h';
        }

        return new self($metrics, $timespans, $defaultMetric, $defaultTimespan);
    }

    /** @return array{metrics:array<string,array{key:string,label:string}>,timespans:array<string,array{key:string,label:string,range:string,granularity:string,refresh:int}>,default_metric:string,default_timespan:string} */
    public function toArray(): array
    {
        return [
            'metrics' => $this->metrics,
            'timespans' => $this->timespans,
            'default_metric' => $this->defaultMetric,
            'default_timespan' => $this->defaultTimespan,
        ];
    }

    /** @return array<string,array{key:string,label:string}> */
    private static function defaultMetrics(): array
    {
        $metrics = [];
        foreach (self::DEFAULT_METRIC_LABELS as $key => $label) {
            $metrics[$key] = ['key' => $key, 'label' => $label];
        }

        return $metrics;
    }

    /** @return array<string,array{key:string,label:string,range:string,granularity:string,refresh:int}> */
    private static function defaultTimespans(MetricQueryPolicy $queryPolicy): array
    {
        $timespans = [];
        foreach (self::DEFAULT_TIMESPANS as $key => $entry) {
            $timespans[$key] = [
                'key' => $key,
                'label' => $entry['label'],
                'range' => $entry['range'],
                'granularity' => $queryPolicy->resolveGranularity($entry['range'], $entry['granularity']),
                'refresh' => $entry['refresh'],
            ];
        }

        return $timespans;
    }

    /**
     * @param array<int|string,mixed> $entries
     * @param callable(string):void   $reportInvalid
     *
     * @return array<string,array{key:string,label:string}>
     */
    private static function normalizeMetrics(
        array $entries,
        MetricCatalog $catalog,
        MetricQueryPolicy $queryPolicy,
        callable $reportInvalid,
    ): array {
        $metrics = [];
        foreach ($entries as $key => $value) {
            if (is_string($key) && !is_array($value)) {
                $reportInvalid("charts.json dashboard metric '{$key}' must be an object -> ignored");

                continue;
            }

            $metricKey = self::entryKey($key, $value, 'metric', $reportInvalid);
            if ($metricKey === null) {
                continue;
            }

            $metricKey = $queryPolicy->normalizeMetric($metricKey);
            if (!$catalog->supports($metricKey)) {
                $reportInvalid("charts.json dashboard references unknown metric '{$metricKey}' -> ignored");

                continue;
            }
            if (is_array($value) && array_key_exists('label', $value) && !is_string($value['label'])) {
                $reportInvalid("charts.json dashboard metric '{$metricKey}' label must be a string -> ignored");

                continue;
            }

            $metrics[$metricKey] = [
                'key' => $metricKey,
                'label' => is_array($value) && isset($value['label'])
                    ? $value['label']
                    : self::DEFAULT_METRIC_LABELS[$metricKey],
            ];
        }

        return $metrics;
    }

    /**
     * @param array<int|string,mixed> $entries
     * @param callable(string):void   $reportInvalid
     *
     * @return array<string,array{key:string,label:string,range:string,granularity:string,refresh:int}>
     */
    private static function normalizeTimespans(
        array $entries,
        MetricCatalog $catalog,
        MetricQueryPolicy $queryPolicy,
        callable $reportInvalid,
    ): array {
        $timespans = [];
        foreach ($entries as $key => $value) {
            if (is_string($key) && !is_array($value)) {
                $reportInvalid("charts.json dashboard timespan '{$key}' must be an object -> ignored");

                continue;
            }

            $timespanKey = self::entryKey($key, $value, 'timespan', $reportInvalid);
            if ($timespanKey === null) {
                continue;
            }
            $timespanKey = strtolower(trim($timespanKey));
            if ($timespanKey === '') {
                $reportInvalid('charts.json dashboard timespan key must not be empty -> ignored');

                continue;
            }

            $rangeValue = is_array($value) && array_key_exists('range', $value) ? $value['range'] : $timespanKey;
            if (!is_string($rangeValue)) {
                $reportInvalid("charts.json dashboard timespan '{$timespanKey}' range must be a string -> ignored");

                continue;
            }
            $range = strtolower(trim($rangeValue));
            if ($range === '' || (!in_array($range, ['all', 'max'], true) && $queryPolicy->parseRange($range) === null)) {
                $reportInvalid("charts.json dashboard timespan '{$timespanKey}' has an invalid range -> ignored");

                continue;
            }
            if (is_array($value) && array_key_exists('label', $value) && !is_string($value['label'])) {
                $reportInvalid("charts.json dashboard timespan '{$timespanKey}' label must be a string -> ignored");

                continue;
            }

            $granularityValue = is_array($value) && array_key_exists('granularity', $value) ? $value['granularity'] : 'auto';
            if (!is_string($granularityValue)) {
                $reportInvalid("charts.json dashboard timespan '{$timespanKey}' granularity must be a string -> ignored");

                continue;
            }
            $granularity = strtolower(trim($granularityValue));
            if (!$catalog->allowsGranularity($granularity)) {
                $reportInvalid("charts.json dashboard timespan '{$timespanKey}' has an invalid granularity -> ignored");

                continue;
            }

            $refresh = is_array($value) && array_key_exists('refresh', $value) ? $value['refresh'] : 60;
            if (!is_int($refresh) || $refresh < 10) {
                $reportInvalid("charts.json dashboard timespan '{$timespanKey}' refresh must be an integer of at least 10 -> ignored");

                continue;
            }

            $timespans[$timespanKey] = [
                'key' => $timespanKey,
                'label' => is_array($value) && isset($value['label']) ? $value['label'] : strtoupper($timespanKey),
                'range' => $range,
                'granularity' => $queryPolicy->resolveGranularity($range, $granularity),
                'refresh' => $refresh,
            ];
        }

        return $timespans;
    }

    /**
     * @param callable(string):void $reportInvalid
     */
    private static function entryKey(
        int|string $key,
        mixed $value,
        string $type,
        callable $reportInvalid,
    ): ?string {
        if (is_array($value)) {
            if (!array_key_exists('key', $value)) {
                if (is_int($key)) {
                    $reportInvalid("charts.json dashboard list {$type} entries require a 'key' field -> ignored");

                    return null;
                }

                return $key;
            }
            if (!is_string($value['key'])) {
                $reportInvalid("charts.json dashboard {$type} entry key must be a string -> ignored");

                return null;
            }

            return $value['key'];
        }

        if (is_int($key)) {
            if (!is_string($value)) {
                $reportInvalid("charts.json dashboard list {$type} entries must be strings or objects -> ignored");

                return null;
            }

            return $value;
        }

        return $key;
    }
}
