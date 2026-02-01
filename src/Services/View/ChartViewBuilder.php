<?php

declare(strict_types=1);

namespace Mata\Services\View;

use InvalidArgumentException;
use Mata\Services\DataTransformers\ChartDataTransformer;
use Mata\Services\Validation\MetricsRequestValidator;
use Mata\Views\MetricsRenderer;

/**
 * Chart view builder for dashboard metrics visualizations.
 *
 * Builds chart configurations, normalizes user inputs, and constructs
 * multi-server chart payloads for time-series visualizations.
 */
class ChartViewBuilder
{
    /**
     * Default chart configuration used for the dashboard overview when no
     * environment-specific overrides are provided.
     *
     * @var array<string,mixed>
     */
    private const DEFAULT_CHART_CONFIG = [
        'default_metric' => 'cpu',
        'default_timespan' => '24h',
        'metrics' => [
            'cpu' => ['label' => 'CPU Usage (%)'],
            'ram_percent' => ['label' => 'RAM Usage (%)'],
            'disk_percent' => ['label' => 'Disk Usage (%)'],
            'process_count' => ['label' => 'Process Count'],
        ],
        'timespans' => [
            '24h' => [
                'label' => '24 Hours',
                'range' => '24h',
                'granularity' => 'hourly',
                'refresh' => 60,
            ],
            '7d' => [
                'label' => '7 Days',
                'range' => '7d',
                'granularity' => 'daily',
                'refresh' => 300,
            ],
        ],
    ];

    private const DASHBOARD_DATA_ENDPOINT = '/api/metrics/dashboard';

    /** @var string[] */
    private const SUPPORTED_METRICS = [
        'cpu',
        'ram_percent',
        'ram_used',
        'disk_percent',
        'disk_used',
        'file_count',
        'process_count',
    ];

    private MetricsViewBuilder $metricsViewBuilder;
    private MetricsRenderer $metricsRenderer;
    private MetricsRequestValidator $validator;
    private ChartDataTransformer $chartTransformer;

    /** @var array<string,mixed> */
    private array $serversConfig;

    /**
     * @param array<string,mixed> $serversConfig
     */
    public function __construct(
        MetricsViewBuilder $metricsViewBuilder,
        MetricsRenderer $metricsRenderer,
        MetricsRequestValidator $validator,
        ChartDataTransformer $chartTransformer,
        array $serversConfig
    ) {
        $this->metricsViewBuilder = $metricsViewBuilder;
        $this->metricsRenderer = $metricsRenderer;
        $this->validator = $validator;
        $this->chartTransformer = $chartTransformer;
        $this->serversConfig = $serversConfig;
    }

    /**
     * Build dashboard overview data for initial chart rendering.
     *
     * @param array<string,mixed> $chartConfig Chart configuration (metrics, timespans, defaults)
     * @param int                 $cacheTtl    Cache TTL for dashboard data
     *
     * @return array<string,mixed>
     */
    public function buildDashboardOverviewData(
        array $chartConfig,
        int $cacheTtl
    ): array {
        $defaultTimespan = $chartConfig['default_timespan'];
        $refreshSeconds = (int) ($chartConfig['timespans'][$defaultTimespan]['refresh'] ?? $cacheTtl);

        return [
            'metrics' => $this->formatMetricsForResponse(
                $chartConfig['metrics'],
                $chartConfig['default_metric']
            ),
            'timespans' => $this->formatTimespansForResponse(
                $chartConfig['timespans'],
                $defaultTimespan
            ),
            'servers' => $this->mapServersForView($this->serversConfig),
            'chart' => [
                'element_id' => 'metrics-dashboard-chart',
                'default_metric' => $chartConfig['default_metric'],
                'default_timespan' => $defaultTimespan,
                'data_endpoint' => self::DASHBOARD_DATA_ENDPOINT,
                'refresh_seconds' => $refreshSeconds,
                'cache_ttl' => $cacheTtl,
            ],
        ];
    }

    /**
     * Build complete dashboard metrics payload with multi-server chart data.
     *
     * @param string[]            $servers     Server names to include in chart
     * @param string              $metric      Metric key (cpu, ram_percent, etc.)
     * @param string              $range       Time range (24h, 7d, etc.)
     * @param string              $granularity Data granularity (raw, hourly, daily)
     * @param string              $timespanKey Timespan configuration key
     * @param array<string,mixed> $chartConfig Chart configuration
     *
     * @return array<string,mixed>
     */
    public function buildDashboardMetricsPayload(
        array $servers,
        string $metric,
        string $range,
        string $granularity,
        string $timespanKey,
        array $chartConfig
    ): array {
        $labels = [];
        $datasets = [];
        $stats = [];
        $unit = '';

        foreach ($servers as $serverName) {
            $series = $this->metricsViewBuilder->buildServerMetricTimeSeries(
                $serverName,
                $metric,
                $range,
                $granularity
            );

            if ($labels === []) {
                $labels = $series['labels'];
            }

            $alignedData = $this->chartTransformer->alignSeriesData(
                $labels,
                $series['labels'],
                $series['data']
            );
            $color = $this->resolveServerPrimaryColor($serverName);

            $datasets[] = $this->chartTransformer->buildDatasetEntry(
                $serverName,
                $this->resolveServerDisplayName($serverName),
                $alignedData,
                $color
            );
            $stats[] = $this->chartTransformer->buildServerStats(
                $serverName,
                $this->resolveServerDisplayName($serverName),
                $series,
                $alignedData
            );

            if ($unit === '') {
                $unit = $series['unit'];
            }

            // Extend labels if data introduced new timestamps
            if (count($series['labels']) > count($labels)) {
                $labels = $series['labels'];
                // Realign all existing datasets/statistics to new labels
                foreach ($datasets as $index => $dataset) {
                    if (!isset($dataset['server'])) {
                        continue;
                    }

                    $serverKey = (string) $dataset['server'];
                    $reSeries = $this->metricsViewBuilder->buildServerMetricTimeSeries(
                        $serverKey,
                        $metric,
                        $range,
                        $granularity
                    );
                    $datasets[$index]['data'] = $this->chartTransformer->alignSeriesData(
                        $labels,
                        $reSeries['labels'],
                        $reSeries['data']
                    );
                    $stats[$index] = $this->chartTransformer->buildServerStats(
                        $serverKey,
                        $this->resolveServerDisplayName($serverKey),
                        $reSeries,
                        $datasets[$index]['data']
                    );
                }
            }
        }

        $timespanConfig = $chartConfig['timespans'][$timespanKey];

        return [
            'chart_type' => 'line',
            'labels' => $labels,
            'datasets' => $datasets,
            'stats' => [
                'metric' => $metric,
                'metric_label' => $this->metricsRenderer->getMetricLabel($metric),
                'timespan' => $timespanKey,
                'timespan_label' => (string) ($timespanConfig['label'] ?? $timespanKey),
                'unit' => $unit,
                'servers' => $stats,
            ],
            'filters' => [
                'metric' => $metric,
                'timespan' => $timespanKey,
                'range' => $range,
                'granularity' => $granularity,
                'servers' => $servers,
            ],
            'available_metrics' => $this->formatMetricsForResponse(
                $chartConfig['metrics'],
                $metric
            ),
            'available_timespans' => $this->formatTimespansForResponse(
                $chartConfig['timespans'],
                $timespanKey
            ),
            'metadata' => [
                'data_endpoint' => self::DASHBOARD_DATA_ENDPOINT,
                'refresh_seconds' => (int) ($timespanConfig['refresh'] ?? 60),
                'cache_ttl' => 60,
            ],
        ];
    }

    /**
     * Build and normalize chart configuration from user overrides.
     *
     * @param array<string,mixed> $overrides User-provided configuration overrides
     *
     * @return array<string,mixed> Normalized chart configuration
     */
    public function buildChartConfig(array $overrides): array
    {
        $defaultConfig = self::DEFAULT_CHART_CONFIG;

        $metricsSource = $defaultConfig['metrics'];
        if (isset($overrides['metrics']) && is_array($overrides['metrics']) && $overrides['metrics'] !== []) {
            $metricsSource = $overrides['metrics'];
        }

        $timespansSource = $defaultConfig['timespans'];
        if (isset($overrides['timespans']) && is_array($overrides['timespans']) && $overrides['timespans'] !== []) {
            $timespansSource = $overrides['timespans'];
        }

        $metrics = $this->normalizeMetricsConfig($metricsSource);
        $timespans = $this->normalizeTimespansConfig($timespansSource);

        $defaultMetric = isset($overrides['default_metric'])
            ? $this->validator->normalizeMetric((string) $overrides['default_metric'])
            : (string) $defaultConfig['default_metric'];
        if (!isset($metrics[$defaultMetric])) {
            $defaultMetric = array_key_first($metrics) ?? 'cpu';
        }

        $defaultTimespan = isset($overrides['default_timespan'])
            ? strtolower((string) $overrides['default_timespan'])
            : (string) $defaultConfig['default_timespan'];
        if (!isset($timespans[$defaultTimespan])) {
            $defaultTimespan = array_key_first($timespans) ?? '24h';
        }

        return [
            'metrics' => $metrics,
            'timespans' => $timespans,
            'default_metric' => $defaultMetric,
            'default_timespan' => $defaultTimespan,
        ];
    }

    /**
     * Resolve dashboard servers from user input.
     *
     * @param string $serversInput Comma-separated server names (empty = all)
     *
     * @return string[] Resolved server names
     */
    public function resolveDashboardServers(string $serversInput): array
    {
        $available = array_keys($this->serversConfig);
        if ($available === []) {
            return ['demo-server'];
        }

        $available = array_map('strval', $available);

        if (trim($serversInput) === '') {
            return $available;
        }

        $requested = array_filter(array_map(
            static fn ($value) => strtolower(trim((string) $value)),
            explode(',', $serversInput)
        ));

        if ($requested === []) {
            return $available;
        }

        $matched = [];
        foreach ($available as $server) {
            if (in_array(strtolower($server), $requested, true)) {
                $matched[] = $server;
            }
        }

        return $matched === [] ? $available : $matched;
    }

    /**
     * @param array<int,mixed>|array<string,mixed> $metricsConfig
     *
     * @return array<string,array{key:string,label:string}>
     */
    private function normalizeMetricsConfig(array $metricsConfig): array
    {
        $normalized = [];

        foreach ($metricsConfig as $key => $value) {
            if (is_array($value)) {
                $metricKey = $this->validator->normalizeMetric((string) ($value['key'] ?? $key));
                $label = isset($value['label']) && is_string($value['label'])
                    ? $value['label']
                    : $this->metricsRenderer->getMetricLabel($metricKey);
            } else {
                $metricKey = $this->validator->normalizeMetric(is_string($value) ? $value : (string) $key);
                $label = $this->metricsRenderer->getMetricLabel($metricKey);
            }

            if (!in_array($metricKey, self::SUPPORTED_METRICS, true)) {
                continue;
            }

            $normalized[$metricKey] = [
                'key' => $metricKey,
                'label' => $label,
            ];
        }

        if ($normalized === []) {
            foreach (self::DEFAULT_CHART_CONFIG['metrics'] as $metricKey => $meta) {
                if (!in_array($metricKey, self::SUPPORTED_METRICS, true)) {
                    continue;
                }
                $normalized[$metricKey] = [
                    'key' => $metricKey,
                    'label' => $meta['label'],
                ];
            }
        }

        return $normalized;
    }

    /**
     * @param array<int,mixed>|array<string,mixed> $timespansConfig
     *
     * @return array<string,array{key:string,label:string,range:string,granularity:string,refresh:int}>
     */
    private function normalizeTimespansConfig(array $timespansConfig): array
    {
        $normalized = [];

        foreach ($timespansConfig as $key => $value) {
            if (is_array($value)) {
                $timespanKey = strtolower((string) ($value['key'] ?? $key));
                $label = isset($value['label']) && is_string($value['label'])
                    ? $value['label']
                    : strtoupper($timespanKey);
                $range = strtolower((string) ($value['range'] ?? $timespanKey));
                $granularity = strtolower((string) ($value['granularity'] ?? 'auto'));
                $refresh = isset($value['refresh']) ? (int) $value['refresh'] : 60;
            } else {
                $timespanKey = strtolower(is_string($value) ? $value : (string) $key);
                $label = strtoupper($timespanKey);
                $range = $timespanKey;
                $granularity = 'auto';
                $refresh = 60;
            }

            if ($range !== '' && !in_array($range, ['all', 'max'], true) && $this->validator->parseRange($range) === null) {
                continue;
            }

            if ($granularity === 'auto') {
                try {
                    $granularity = $this->validator->resolveGranularity($range, 'auto');
                } catch (InvalidArgumentException) {
                    $granularity = 'hourly';
                }
            }

            $normalized[$timespanKey] = [
                'key' => $timespanKey,
                'label' => $label,
                'range' => $range,
                'granularity' => $granularity,
                'refresh' => max(10, $refresh),
            ];
        }

        if ($normalized === []) {
            foreach (self::DEFAULT_CHART_CONFIG['timespans'] as $key => $meta) {
                $normalized[$key] = [
                    'key' => (string) $key,
                    'label' => $meta['label'],
                    'range' => $meta['range'],
                    'granularity' => $meta['granularity'],
                    'refresh' => $meta['refresh'],
                ];
            }
        }

        return $normalized;
    }

    /**
     * @param array<string,array{key:string,label:string}> $metrics
     *
     * @return array<int,array<string,mixed>>
     */
    private function formatMetricsForResponse(array $metrics, string $activeMetric): array
    {
        $result = [];

        foreach ($metrics as $key => $config) {
            $result[] = [
                'key' => $key,
                'label' => $config['label'],
                'is_active' => $key === $activeMetric,
            ];
        }

        return $result;
    }

    /**
     * @param array<string,array{key:string,label:string,range:string,granularity:string,refresh:int}> $timespans
     *
     * @return array<int,array<string,mixed>>
     */
    private function formatTimespansForResponse(array $timespans, string $activeTimespan): array
    {
        $result = [];

        foreach ($timespans as $key => $config) {
            $result[] = [
                'key' => $key,
                'label' => $config['label'],
                'range' => $config['range'],
                'granularity' => $config['granularity'],
                'refresh_seconds' => $config['refresh'],
                'is_active' => $key === $activeTimespan,
            ];
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $serversConfig
     *
     * @return array<int,array<string,string>>
     */
    private function mapServersForView(array $serversConfig): array
    {
        if ($serversConfig === []) {
            return [[
                'name' => 'demo-server',
                'label' => 'Demo Server',
                'color' => '#3273dc',
            ]];
        }

        $servers = [];
        foreach ($serversConfig as $name => $config) {
            if (!is_array($config)) {
                continue;
            }

            $serverName = (string) $name;
            $servers[] = [
                'name' => $serverName,
                'label' => $this->getServerDisplayName($serverName, $config),
                'color' => $this->getServerPrimaryColor($serverName, $config),
            ];
        }

        return $servers;
    }

    /**
     * @param array<string,mixed> $serverConfig
     */
    private function getServerDisplayName(string $serverName, array $serverConfig): string
    {
        $title = $serverConfig['title'] ?? null;

        if (is_string($title) && $title !== '') {
            return $title;
        }

        $clean = str_replace(['-', '_'], ' ', $serverName);

        return ucwords($clean);
    }

    /**
     * @param array<string,mixed> $serverConfig
     */
    private function getServerPrimaryColor(string $serverName, array $serverConfig): string
    {
        $color = $serverConfig['primary_color'] ?? null;

        if (is_string($color) && preg_match('/^#([0-9a-f]{6})$/i', $color) === 1) {
            return strtoupper($color);
        }

        return '#3273dc';
    }

    /**
     * Resolve server display name from stored server configuration.
     */
    private function resolveServerDisplayName(string $serverName): string
    {
        $serverConfig = $this->serversConfig[$serverName] ?? null;
        if (!is_array($serverConfig)) {
            $clean = str_replace(['-', '_'], ' ', $serverName);

            return ucwords($clean);
        }

        return $this->getServerDisplayName($serverName, $serverConfig);
    }

    /**
     * Resolve server primary color from stored server configuration.
     */
    private function resolveServerPrimaryColor(string $serverName): string
    {
        $serverConfig = $this->serversConfig[$serverName] ?? null;
        if (!is_array($serverConfig)) {
            return '#3273dc';
        }

        return $this->getServerPrimaryColor($serverName, $serverConfig);
    }
}
