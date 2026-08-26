<?php

declare(strict_types=1);

namespace Mata\Services\View;

use Mata\Config\Monitoring\Section\DashboardChartConfig;
use Mata\Services\DataTransformers\ChartDataTransformer;
use Mata\Views\MetricsRenderer;

/**
 * Chart view builder for dashboard metrics visualizations.
 *
 * Builds chart configurations, normalizes user inputs, and constructs
 * multi-server chart payloads for time-series visualizations.
 */
class ChartViewBuilder
{
    private const DASHBOARD_DATA_ENDPOINT = '/api/metrics/dashboard';

    private MetricsViewBuilder $metricsViewBuilder;
    private MetricsRenderer $metricsRenderer;
    private ChartDataTransformer $chartTransformer;

    /** @var array<string,mixed> */
    private array $serversConfig;

    /**
     * @param array<string,mixed> $serversConfig
     */
    public function __construct(
        MetricsViewBuilder $metricsViewBuilder,
        MetricsRenderer $metricsRenderer,
        ChartDataTransformer $chartTransformer,
        array $serversConfig
    ) {
        $this->metricsViewBuilder = $metricsViewBuilder;
        $this->metricsRenderer = $metricsRenderer;
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
                'metric_label' => (string) ($chartConfig['metrics'][$metric]['label']
                    ?? $this->metricsRenderer->getMetricLabel($metric)),
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

    /** @return array<string,mixed> */
    public function buildChartConfig(DashboardChartConfig $config): array
    {
        return $config->toArray();
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
