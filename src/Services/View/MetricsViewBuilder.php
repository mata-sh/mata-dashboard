<?php

declare(strict_types=1);

namespace Mata\Services\View;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Mata\Models\ServerMetrics\Dto\ServerMetricDailyAggregate;
use Mata\Models\ServerMetrics\Dto\ServerMetricHourlyAggregate;
use Mata\Models\ServerMetrics\Dto\ServerMetricRawSnapshot;
use Mata\Models\ServerMetrics\ServerMetricsQueryInterface;
use Psr\Log\LoggerInterface;

/**
 * Metrics view data builder service.
 *
 * Transforms ServerMetricsQuery results into visualization-ready formats:
 * - Chart data structures (labels, datasets, options)
 * - Time-series data for various metrics
 * - Multi-server comparison data
 * - Handles granularity selection and time range parsing
 */
class MetricsViewBuilder
{
    private ServerMetricsQueryInterface $metricsQuery;
    private LoggerInterface $logger;

    public function __construct(
        ServerMetricsQueryInterface $metricsQuery,
        LoggerInterface $logger
    ) {
        $this->metricsQuery = $metricsQuery;
        $this->logger = $logger;
    }

    /**
     * Build chart-ready time series for a specific metric.
     *
     * Fetches metric data from ServerMetricsQuery and transforms it into a
     * visualization-friendly format with labels, data points, and statistics.
     * Automatically handles different granularities and DTO types.
     *
     * @example
     * // Get CPU usage for last 24 hours with hourly granularity
     * $builder->buildServerMetricTimeSeries('prod-server', 'cpu', '24h', 'hourly');
     * // Returns: ['labels' => ['2024-01-15 00:00', ...], 'data' => [45.2, ...], 'min' => 12.5, 'max' => 89.3, ...]
     * @example
     * // Get process count for last 30 days with daily aggregation
     * $builder->buildServerMetricTimeSeries('prod-server', 'process_count', '30d', 'daily');
     * // Returns: ['labels' => ['2024-01-01', '2024-01-02', ...], 'data' => [145, 152, ...], ...]
     * @example
     * // Get RAM percentage for last 7 days with hourly data
     * $builder->buildServerMetricTimeSeries('prod-server', 'ram_percent', '7d', 'hourly');
     * // Returns: ['labels' => [...], 'data' => [65.4, 67.2, ...], 'unit' => '%', ...]
     *
     * @param string $serverName  Server identifier from servers.json
     * @param string $metric      Metric key (cpu, ram_percent, ram_used, disk_percent, etc.)
     * @param string $range       Time range (24h, 7d, 30d, all)
     * @param string $granularity Data granularity (raw, hourly, daily)
     *
     * @return array{
     *     server_name: string,
     *     metric: string,
     *     range: string,
     *     granularity: string,
     *     labels: array<int,string>,
     *     data: array<int,null|float|int>,
     *     min: null|float|int,
     *     max: null|float|int,
     *     avg: null|float,
     *     unit: string
     * } Chart-ready time-series data with labels, values, and statistics
     */
    public function buildServerMetricTimeSeries(
        string $serverName,
        string $metric,
        string $range = '24h',
        string $granularity = 'hourly'
    ): array {
        ['start' => $start, 'end' => $end] = $this->parseTimeRange($range, $granularity);

        $dataPoints = match ($granularity) {
            'daily' => $this->metricsQuery->getDailyMetrics($serverName, $start, $end),
            'hourly' => $this->metricsQuery->getHourlyMetrics($serverName, $start, $end),
            'raw' => $this->metricsQuery->getRawMetrics($serverName, $start, $end),
            default => throw new InvalidArgumentException("Unsupported granularity: {$granularity}"),
        };

        if ($dataPoints === []) {
            $this->logger->info('metrics.empty_data_points', [
                'server' => $serverName,
                'metric' => $metric,
                'range' => $range,
                'granularity' => $granularity,
                'start' => $start->format('Y-m-d H:i:s'),
                'end' => $end->format('Y-m-d H:i:s'),
            ]);

            return $this->emptyTimeSeries($serverName, $metric, $range, $granularity);
        }

        $labels = [];
        $data = [];

        foreach ($dataPoints as $point) {
            $labels[] = $this->formatTimestamp($point, $granularity);
            $data[] = $this->extractMetricValue($point, $metric);
        }

        $numericData = array_filter($data, fn ($v) => $v !== null);

        return [
            'server_name' => $serverName,
            'metric' => $metric,
            'range' => $range,
            'granularity' => $granularity,
            'labels' => $labels,
            'data' => $data,
            'min' => !empty($numericData) ? min($numericData) : null,
            'max' => !empty($numericData) ? max($numericData) : null,
            'avg' => !empty($numericData) ? round(array_sum($numericData) / count($numericData), 2) : null,
            'unit' => $this->getMetricUnit($metric),
        ];
    }

    /**
     * Build chart-ready data for multiple metrics on a single server.
     *
     * Fetches data once and transforms it into multiple datasets, one per metric.
     * Useful for comparison charts showing CPU, RAM, and Disk together to identify
     * correlations between different resource metrics over time.
     *
     * @example
     * // Compare CPU, RAM, and Disk usage over last 24 hours
     * $builder->buildMultiMetricTimeSeries('prod-server', ['cpu', 'ram_percent', 'disk_percent'], '24h', 'hourly');
     * // Returns: ['labels' => [...], 'datasets' => [
     * //   ['metric' => 'cpu', 'data' => [45.2, ...], 'unit' => '%'],
     * //   ['metric' => 'ram_percent', 'data' => [67.4, ...], 'unit' => '%'],
     * //   ['metric' => 'disk_percent', 'data' => [82.1, ...], 'unit' => '%']
     * // ]]
     * @example
     * // Compare RAM used vs Disk used over last 7 days
     * $builder->buildMultiMetricTimeSeries('prod-server', ['ram_used', 'disk_used'], '7d', 'daily');
     * // Returns: ['labels' => ['2024-01-01', ...], 'datasets' => [
     * //   ['metric' => 'ram_used', 'data' => [4294967296, ...], 'unit' => 'bytes'],
     * //   ['metric' => 'disk_used', 'data' => [107374182400, ...], 'unit' => 'bytes']
     * // ]]
     *
     * @param string            $serverName  Server identifier from servers.json
     * @param array<int,string> $metrics     List of metric keys to include
     * @param string            $range       Time range (24h, 7d, 30d, all)
     * @param string            $granularity Data granularity (raw, hourly, daily)
     *
     * @return array{
     *     server_name: string,
     *     range: string,
     *     granularity: string,
     *     labels: array<int,string>,
     *     datasets: array<int,array{metric:string,data:array<int,null|float|int>,unit:string}>
     * } Multi-dataset chart data with shared labels and per-metric data arrays
     */
    public function buildMultiMetricTimeSeries(
        string $serverName,
        array $metrics,
        string $range = '24h',
        string $granularity = 'hourly'
    ): array {
        ['start' => $start, 'end' => $end] = $this->parseTimeRange($range, $granularity);

        $dataPoints = match ($granularity) {
            'daily' => $this->metricsQuery->getDailyMetrics($serverName, $start, $end),
            'hourly' => $this->metricsQuery->getHourlyMetrics($serverName, $start, $end),
            'raw' => $this->metricsQuery->getRawMetrics($serverName, $start, $end),
            default => throw new InvalidArgumentException("Unsupported granularity: {$granularity}"),
        };

        if ($dataPoints === []) {
            return [
                'server_name' => $serverName,
                'range' => $range,
                'granularity' => $granularity,
                'labels' => [],
                'datasets' => [],
            ];
        }

        $labels = [];
        $datasets = [];

        // Extract labels from first pass
        foreach ($dataPoints as $point) {
            $labels[] = $this->formatTimestamp($point, $granularity);
        }

        // Build datasets for each metric
        foreach ($metrics as $metric) {
            $data = [];
            foreach ($dataPoints as $point) {
                $data[] = $this->extractMetricValue($point, $metric);
            }

            $datasets[] = [
                'metric' => $metric,
                'data' => $data,
                'unit' => $this->getMetricUnit($metric),
            ];
        }

        return [
            'server_name' => $serverName,
            'range' => $range,
            'granularity' => $granularity,
            'labels' => $labels,
            'datasets' => $datasets,
        ];
    }

    /**
     * Parse time range string into DateTimeImmutable boundaries.
     *
     * @param string $range       Range string (24h, 7d, 30d, all)
     * @param string $granularity Granularity for boundary alignment
     *
     * @return array{start: DateTimeImmutable, end: DateTimeImmutable}
     */
    private function parseTimeRange(string $range, string $granularity): array
    {
        $normalizedRange = strtolower(trim($range));
        $utc = new DateTimeZone('UTC');
        $end = new DateTimeImmutable('now', $utc);

        // Align end boundary based on granularity
        if ($granularity === 'daily') {
            $end = $end->setTime(0, 0, 0);
        } elseif ($granularity === 'hourly') {
            $end = $end->setTime((int) $end->format('H'), 0, 0);
        }

        // Handle "all time" ranges
        if ($normalizedRange === '' || $normalizedRange === 'all' || $normalizedRange === 'max') {
            $start = new DateTimeImmutable('1970-01-01 00:00:00', $utc);

            return ['start' => $start, 'end' => $end];
        }

        // Parse interval from range string (e.g., "24h", "7d")
        $interval = $this->parseInterval($normalizedRange);
        $start = $end->sub($interval);

        // Align start boundary based on granularity
        if ($granularity === 'daily') {
            $start = $start->setTime(0, 0, 0);
        } elseif ($granularity === 'hourly') {
            $start = $start->setTime((int) $start->format('H'), 0, 0);
        }

        return ['start' => $start, 'end' => $end];
    }

    /**
     * Parse range string into DateInterval.
     *
     * @param string $range Range string like "24h", "7d", "30d"
     */
    private function parseInterval(string $range): DateInterval
    {
        if (!preg_match('/^(\d+)([hdwmy])$/', $range, $matches)) {
            throw new InvalidArgumentException(
                sprintf('Invalid range format "%s". Use format like 24h, 7d, 30d.', $range)
            );
        }

        $value = (int) $matches[1];
        $unit = $matches[2];

        if ($value <= 0) {
            throw new InvalidArgumentException('Range value must be greater than zero.');
        }

        return match ($unit) {
            'h' => new DateInterval('PT' . $value . 'H'),
            'd' => new DateInterval('P' . $value . 'D'),
            'w' => new DateInterval('P' . $value . 'W'),
            'm' => new DateInterval('P' . $value . 'M'),
            'y' => new DateInterval('P' . $value . 'Y'),
        };
    }

    /**
     * Format timestamp from DTO based on granularity.
     */
    private function formatTimestamp(
        ServerMetricDailyAggregate|ServerMetricHourlyAggregate|ServerMetricRawSnapshot $point,
        string $granularity
    ): string {
        $timestamp = match (true) {
            $point instanceof ServerMetricDailyAggregate => $point->date,
            $point instanceof ServerMetricHourlyAggregate => $point->hourTimestamp,
            default => $point->collectedAt, // ServerMetricRawSnapshot (default)
        };

        return match ($granularity) {
            'daily' => $timestamp->format('Y-m-d'),
            'hourly' => $timestamp->format('Y-m-d H:i'),
            'raw' => $timestamp->format('Y-m-d H:i:s'),
            default => $timestamp->format('c'),
        };
    }

    /**
     * Extract specific metric value from DTO.
     */
    private function extractMetricValue(
        ServerMetricDailyAggregate|ServerMetricHourlyAggregate|ServerMetricRawSnapshot $point,
        string $metric
    ): float|int|null {
        return match ($metric) {
            'cpu' => $this->getCpuValue($point),
            'ram_percent' => $this->getRamPercentValue($point),
            'ram_used' => $this->getRamUsedValue($point),
            'disk_percent' => $this->getDiskPercentValue($point),
            'disk_used' => $this->getDiskUsedValue($point),
            'file_count' => $this->getFileCountValue($point),
            'process_count' => $this->getProcessCountValue($point),
            default => throw new InvalidArgumentException("Unsupported metric: {$metric}"),
        };
    }

    private function getCpuValue(
        ServerMetricDailyAggregate|ServerMetricHourlyAggregate|ServerMetricRawSnapshot $point
    ): ?float {
        return match (true) {
            $point instanceof ServerMetricDailyAggregate => $point->cpuAvg,
            $point instanceof ServerMetricHourlyAggregate => $point->cpuAvg,
            default => $point->cpuUsage, // ServerMetricRawSnapshot (default)
        };
    }

    private function getRamPercentValue(
        ServerMetricDailyAggregate|ServerMetricHourlyAggregate|ServerMetricRawSnapshot $point
    ): ?float {
        return match (true) {
            $point instanceof ServerMetricDailyAggregate => $point->ramPercentAvg,
            $point instanceof ServerMetricHourlyAggregate => $point->ramPercentAvg,
            default => $point->ramUsagePercent, // ServerMetricRawSnapshot (default)
        };
    }

    private function getRamUsedValue(
        ServerMetricDailyAggregate|ServerMetricHourlyAggregate|ServerMetricRawSnapshot $point
    ): ?int {
        return match (true) {
            $point instanceof ServerMetricDailyAggregate => $point->ramUsedAvg,
            $point instanceof ServerMetricHourlyAggregate => $point->ramUsedAvg,
            default => $point->ramUsed, // ServerMetricRawSnapshot (default)
        };
    }

    private function getDiskPercentValue(
        ServerMetricDailyAggregate|ServerMetricHourlyAggregate|ServerMetricRawSnapshot $point
    ): ?float {
        return match (true) {
            $point instanceof ServerMetricDailyAggregate => $point->diskPercentAvg,
            $point instanceof ServerMetricHourlyAggregate => $point->diskPercentAvg,
            default => $point->diskUsagePercent, // ServerMetricRawSnapshot (default)
        };
    }

    private function getDiskUsedValue(
        ServerMetricDailyAggregate|ServerMetricHourlyAggregate|ServerMetricRawSnapshot $point
    ): ?int {
        return match (true) {
            $point instanceof ServerMetricDailyAggregate => $point->diskUsedAvg,
            $point instanceof ServerMetricHourlyAggregate => $point->diskUsedAvg,
            default => $point->diskUsed, // ServerMetricRawSnapshot (default)
        };
    }

    private function getFileCountValue(
        ServerMetricDailyAggregate|ServerMetricHourlyAggregate|ServerMetricRawSnapshot $point
    ): ?int {
        return match (true) {
            $point instanceof ServerMetricDailyAggregate => $point->fileCountAvg,
            $point instanceof ServerMetricHourlyAggregate => $point->fileCountAvg,
            default => $point->totalFileCount, // ServerMetricRawSnapshot (default)
        };
    }

    private function getProcessCountValue(
        ServerMetricDailyAggregate|ServerMetricHourlyAggregate|ServerMetricRawSnapshot $point
    ): ?int {
        return match (true) {
            $point instanceof ServerMetricDailyAggregate => $point->processCountAvg,
            $point instanceof ServerMetricHourlyAggregate => $point->processCountAvg,
            default => $point->processCount, // ServerMetricRawSnapshot (default)
        };
    }

    /**
     * Get unit label for metric.
     */
    private function getMetricUnit(string $metric): string
    {
        return match ($metric) {
            'cpu', 'ram_percent', 'disk_percent' => '%',
            'ram_used', 'disk_used' => 'bytes',
            'file_count', 'process_count' => 'count',
            default => '',
        };
    }

    /**
     * @return array{
     *     server_name: string,
     *     metric: string,
     *     range: string,
     *     granularity: string,
     *     labels: array<int,string>,
     *     data: array<int,null|float|int>,
     *     min: null|float|int,
     *     max: null|float|int,
     *     avg: null|float,
     *     unit: string
     * }
     */
    private function emptyTimeSeries(
        string $serverName,
        string $metric,
        string $range,
        string $granularity
    ): array {
        return [
            'server_name' => $serverName,
            'metric' => $metric,
            'range' => $range,
            'granularity' => $granularity,
            'labels' => [],
            'data' => [],
            'min' => null,
            'max' => null,
            'avg' => null,
            'unit' => $this->getMetricUnit($metric),
        ];
    }
}
