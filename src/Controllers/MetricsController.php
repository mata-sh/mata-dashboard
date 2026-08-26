<?php

declare(strict_types=1);

namespace Mata\Controllers;

use InvalidArgumentException;
use Mata\Config\Monitoring\Section\DashboardChartConfig;
use Mata\Http\Request;
use Mata\Http\Responses\HtmlResponse;
use Mata\Http\Responses\JsonResponse;
use Mata\Http\Responses\Response;
use Mata\Services\Cache\MetricsCacheService;
use Mata\Services\ServerMetrics\MetricCatalog;
use Mata\Services\ServerMetrics\MetricQueryPolicy;
use Mata\Services\ServerMetrics\MetricsRequestValidator;
use Mata\Services\View\ChartViewBuilder;
use Mata\Services\View\MetricsViewBuilder;
use Mata\Views\MetricsRenderer;
use Monolog\Logger;

/**
 * Metrics-specific controller exposing time-series endpoints for charts.
 *
 * Validates dashboard filters, selects appropriate granularity, and delegates
 * to MetricsViewBuilder for chart-ready payloads.
 */
final class MetricsController extends BaseController
{
    private MetricsViewBuilder $metricsViewBuilder;
    private MetricsRenderer $metricsRenderer;
    private MetricsCacheService $cacheService;
    private MetricsRequestValidator $validator;
    private MetricQueryPolicy $queryPolicy;
    private ChartViewBuilder $chartViewBuilder;
    private MetricCatalog $metricCatalog;

    /** @var array<string,mixed> */
    private array $serversConfig;

    /** @var array<string,mixed> */
    private array $dashboardChartConfig = [];

    /** @var array<string,string> */
    private const SERVER_METRICS_MODAL_RANGES = [
        '6h' => '6 hours',
        '24h' => '24 hours',
        '7d' => '7 days',
        '30d' => '30 days',
    ];

    private int $cacheTtlSeconds;
    private int $dashboardCacheTtl = 60;

    /**
     * @param array<string,mixed> $serversConfig
     */
    public function __construct(
        Logger $logger,
        MetricsViewBuilder $metricsViewBuilder,
        MetricsRenderer $metricsRenderer,
        MetricsCacheService $cacheService,
        MetricsRequestValidator $validator,
        MetricQueryPolicy $queryPolicy,
        ChartViewBuilder $chartViewBuilder,
        MetricCatalog $metricCatalog,
        array $serversConfig,
        Request $request,
        DashboardChartConfig $dashboardChartConfig,
        ?int $cacheTtlSeconds = null,
        ?int $dashboardCacheTtl = null,
    ) {
        parent::__construct($request);
        $this->logger = $logger;
        $this->metricsViewBuilder = $metricsViewBuilder;
        $this->metricsRenderer = $metricsRenderer;
        $this->cacheService = $cacheService;
        $this->validator = $validator;
        $this->queryPolicy = $queryPolicy;
        $this->chartViewBuilder = $chartViewBuilder;
        $this->metricCatalog = $metricCatalog;
        $this->serversConfig = $serversConfig;
        $this->cacheTtlSeconds = $cacheTtlSeconds ?? 300;
        $this->dashboardChartConfig = $this->chartViewBuilder->buildChartConfig($dashboardChartConfig);
        $this->dashboardCacheTtl = $dashboardCacheTtl ?? 60;
    }

    /**
     * Render a metrics overview container with interactive chart controls.
     */
    public function renderMetricsOverview(): Response
    {
        return $this->executeWithErrorHandling(
            function () {
                $viewData = $this->chartViewBuilder->buildDashboardOverviewData(
                    $this->dashboardChartConfig,
                    $this->dashboardCacheTtl
                );
                $html = $this->metricsRenderer->renderDashboardOverview($viewData);

                return new HtmlResponse($html);
            },
            'Failed to render metrics overview',
            'MetricsController:renderMetricsOverview'
        );
    }

    /**
     * Provide aggregated metrics data for the dashboard chart endpoint.
     */
    public function getDashboardMetricsData(): Response
    {
        return $this->executeWithErrorHandling(
            function () {
                $metricInput = $this->getQueryParam(
                    'metric',
                    (string) ($this->dashboardChartConfig['default_metric'] ?? '')
                );
                $timespanInput = $this->getQueryParam(
                    'timespan',
                    (string) ($this->dashboardChartConfig['default_timespan'] ?? '')
                );
                $serversInput = $this->getQueryParam('servers', '');
                $forceRefreshInput = $this->getQueryParam('force', '');

                $forceRefresh = in_array(strtolower($forceRefreshInput), ['1', 'true', 'yes'], true);

                $metricKey = $this->queryPolicy->normalizeMetric($metricInput);
                if (!isset($this->dashboardChartConfig['metrics'][$metricKey])) {
                    return $this->handleValidationError([
                        'metric' => sprintf("Unsupported metric '%s'", $metricInput),
                    ]);
                }

                $timespanKey = strtolower(trim($timespanInput));
                if (!isset($this->dashboardChartConfig['timespans'][$timespanKey])) {
                    return $this->handleValidationError([
                        'timespan' => sprintf("Unsupported timespan '%s'", $timespanInput),
                    ]);
                }

                $servers = $this->chartViewBuilder->resolveDashboardServers($serversInput);
                if ($servers === []) {
                    return $this->handleValidationError([
                        'servers' => 'No servers available for metrics dashboard',
                    ]);
                }

                $timespanConfig = $this->dashboardChartConfig['timespans'][$timespanKey];
                $range = (string) $timespanConfig['range'];
                $granularity = (string) $timespanConfig['granularity'];

                $cacheKey = $this->cacheService->buildDashboardCacheKey(
                    $servers,
                    $metricKey,
                    $timespanKey,
                    $range,
                    $granularity,
                );

                $cachedPayload = $this->cacheService->readDashboardCache($cacheKey, $this->dashboardCacheTtl, $forceRefresh);
                if ($cachedPayload !== null) {
                    return new JsonResponse($cachedPayload);
                }

                $payload = $this->chartViewBuilder->buildDashboardMetricsPayload(
                    $servers,
                    $metricKey,
                    $range,
                    $granularity,
                    $timespanKey,
                    $this->dashboardChartConfig
                );

                $this->cacheService->writeDashboardCache($cacheKey, $payload);

                return new JsonResponse($payload);
            },
            'Failed to load dashboard metrics data',
            'MetricsController:getDashboardMetricsData'
        );
    }

    /**
     * Render process count modal with time-series chart for a server.
     *
     * Returns HTML modal content with daily process count metrics over the
     * specified time range. Defaults to 30 days with daily granularity.
     *
     * @example
     * GET /watch/modal/prod-server/process-count
     * GET /watch/modal/prod-server/process-count?range=60d
     *
     * @param string $server Server name from servers.json config
     *
     * @return Response HTML response with modal content or 422 validation error
     */
    public function renderProcessCountModal(string $server): Response
    {
        return $this->executeWithErrorHandling(
            function () use ($server) {
                $requestStartTime = microtime(true);
                $rangeInput = $this->getQueryParam('range', '30d');
                $range = $this->normalizeRangeInput($rangeInput);
                $granularity = 'daily';

                $this->logger->debug('metrics.request.start', [
                    'endpoint' => 'renderProcessCountModal',
                    'server' => $server,
                    'metric' => 'process_count',
                    'range' => $range,
                    'granularity' => $granularity,
                ]);

                $validation = $this->validator->validate(
                    $server,
                    'process_count',
                    $range,
                    $granularity,
                    $this->serversConfig
                );

                if ($validation->hasErrors()) {
                    $this->logger->warning('Metrics modal validation failed', [
                        'server' => $server,
                        'errors' => $validation->errors,
                    ]);

                    return new HtmlResponse(
                        $this->metricsRenderer->renderValidationError($validation->errors),
                        422
                    );
                }

                $metricKey = $validation->normalizedMetric;

                try {
                    $payload = $this->getSeriesPayload($server, $metricKey, $range, $granularity);
                } catch (InvalidArgumentException $exception) {
                    return new HtmlResponse(
                        $this->metricsRenderer->renderError($exception->getMessage()),
                        422
                    );
                }

                $html = $this->metricsRenderer->renderProcessCountModal($server, $payload);

                $durationMs = round((microtime(true) - $requestStartTime) * 1000, 2);
                $dataPoints = isset($payload['series']['data']) && is_array($payload['series']['data'])
                    ? count($payload['series']['data'])
                    : 0;

                $this->logger->debug('metrics.request.complete', [
                    'endpoint' => 'renderProcessCountModal',
                    'server' => $server,
                    'metric' => 'process_count',
                    'range' => $range,
                    'granularity' => $granularity,
                    'data_points' => $dataPoints,
                    'duration_ms' => $durationMs,
                    'from_cache' => $payload['cache']['from_cache'] ?? false,
                ]);

                return new HtmlResponse($html);
            },
            'Failed to render process count metrics',
            "MetricsController:renderProcessCountModal server={$server}"
        );
    }

    /**
     * Render the shared CPU/RAM/disk/files modal used by server cards.
     */
    public function renderServerMetricsModal(string $server): Response
    {
        return $this->executeWithErrorHandling(
            function () use ($server) {
                $metric = $this->queryPolicy->normalizeMetric($this->getQueryParam('metric', 'cpu'));
                $range = $this->normalizeRangeInput($this->getQueryParam('range', '24h'));
                $allowedMetrics = ['cpu', 'ram_percent', 'disk_percent', 'file_count'];

                if (!in_array($metric, $allowedMetrics, true) || !isset(self::SERVER_METRICS_MODAL_RANGES[$range])) {
                    return new HtmlResponse(
                        $this->metricsRenderer->renderValidationError([
                            'filters' => 'Supported metrics are CPU, RAM, disk, and files with ranges 6h, 24h, 7d, or 30d.',
                        ]),
                        422
                    );
                }

                $granularity = $this->queryPolicy->resolveGranularity($range, 'auto');
                $validation = $this->validator->validate(
                    $server,
                    $metric,
                    $range,
                    $granularity,
                    $this->serversConfig
                );
                if ($validation->hasErrors()) {
                    return new HtmlResponse($this->metricsRenderer->renderValidationError($validation->errors), 422);
                }

                return new HtmlResponse($this->metricsRenderer->renderServerMetricsModal(
                    $server,
                    $this->getSeriesPayload($server, $metric, $range, $granularity),
                    $this->serverMetricsModalRanges()
                ));
            },
            'Failed to render server metrics',
            "MetricsController:renderServerMetricsModal server={$server}"
        );
    }

    /**
     * Get server metric time-series data as JSON for chart rendering.
     *
     * Returns chart-ready JSON payload with time-series data for a single metric.
     * Supports automatic granularity selection based on time range, or manual
     * granularity override. Response includes filters, series data, and cache metadata.
     *
     * @example
     * // Get CPU usage for last 24 hours with auto granularity
     * GET /api/metrics/prod-server/cpu?range=24h&granularity=auto
     * @example
     * // Get process count for last 30 days with daily aggregation
     * GET /api/metrics/prod-server/process_count?range=30d&granularity=daily
     * @example
     * // Get RAM percentage for last 7 days with hourly granularity
     * GET /api/metrics/prod-server/ram_percent?range=7d&granularity=hourly
     *
     * @param string $server Server name from servers.json config
     * @param string $metric Metric key (cpu, ram_percent, disk_percent, process_count, etc.)
     *
     * @return Response JSON response with filters, series data, and cache metadata (422 on validation error)
     */
    public function getServerMetricSeries(string $server, string $metric): Response
    {
        return $this->executeWithErrorHandling(
            function () use ($server, $metric) {
                $requestStartTime = microtime(true);
                $rangeInput = $this->getQueryParam('range', '24h');
                $range = $this->normalizeRangeInput($rangeInput);
                $granularityInput = strtolower($this->getQueryParam('granularity', 'auto'));

                $this->logger->debug('metrics.request.start', [
                    'endpoint' => 'getServerMetricSeries',
                    'server' => $server,
                    'metric' => $metric,
                    'range' => $range,
                    'granularity' => $granularityInput,
                ]);

                $validation = $this->validator->validate(
                    $server,
                    $metric,
                    $range,
                    $granularityInput,
                    $this->serversConfig
                );

                if ($validation->hasErrors()) {
                    return $this->handleValidationError($validation->errors);
                }

                $metricKey = $validation->normalizedMetric;

                try {
                    $granularity = $this->queryPolicy->resolveGranularity($range, $granularityInput);
                } catch (InvalidArgumentException $exception) {
                    return $this->handleValidationError(['granularity' => $exception->getMessage()]);
                }

                try {
                    $payload = $this->getSeriesPayload($server, $metricKey, $range, $granularity);
                } catch (InvalidArgumentException $exception) {
                    return $this->handleValidationError(['range' => $exception->getMessage()]);
                }

                $durationMs = round((microtime(true) - $requestStartTime) * 1000, 2);
                $dataPoints = isset($payload['series']['data']) && is_array($payload['series']['data'])
                    ? count($payload['series']['data'])
                    : 0;

                $this->logger->debug('metrics.request.complete', [
                    'endpoint' => 'getServerMetricSeries',
                    'server' => $server,
                    'metric' => $metricKey,
                    'range' => $range,
                    'granularity' => $granularity,
                    'data_points' => $dataPoints,
                    'duration_ms' => $durationMs,
                    'from_cache' => $payload['cache']['from_cache'] ?? false,
                ]);

                return $this->metricsRenderer->renderMetricsJson(
                    $payload['series'],
                    $payload['filters'],
                    $payload['cache']
                );
            },
            'Failed to load server metrics data',
            "MetricsController:getServerMetricSeries server={$server} metric={$metric}"
        );
    }

    /**
     * Get multiple server metrics in a single time-series for comparison charts.
     *
     * Returns chart-ready JSON payload with time-series data for multiple metrics.
     * Useful for displaying CPU, RAM, and Disk metrics together in one chart to
     * identify correlations between different resource metrics.
     *
     * @example
     * // Compare CPU, RAM, and Disk usage over last 24 hours
     * GET /api/metrics/prod-server/compare?metrics=cpu,ram_percent,disk_percent&range=24h&granularity=auto
     * @example
     * // Compare RAM used vs disk used over last 7 days
     * GET /api/metrics/prod-server/compare?metrics=ram_used,disk_used&range=7d&granularity=daily
     *
     * @param string $server Server name from servers.json config
     *
     * @return Response JSON with filters, multi-metric series, and cache metadata (422 on error)
     */
    public function getServerMultiMetricSeries(string $server): Response
    {
        return $this->executeWithErrorHandling(
            function () use ($server) {
                $requestStartTime = microtime(true);
                $metricsInput = $this->getQueryParam('metrics', 'cpu,ram_percent');
                $metrics = array_filter(array_map('trim', explode(',', $metricsInput)));

                if ($metrics === []) {
                    return $this->handleValidationError(['metrics' => 'At least one metric must be specified']);
                }

                $rangeInput = $this->getQueryParam('range', '24h');
                $range = $this->normalizeRangeInput($rangeInput);
                $granularityInput = strtolower($this->getQueryParam('granularity', 'auto'));

                $this->logger->debug('metrics.request.start', [
                    'endpoint' => 'getServerMultiMetricSeries',
                    'server' => $server,
                    'metrics' => $metricsInput,
                    'range' => $range,
                    'granularity' => $granularityInput,
                ]);

                // Validate server
                if ($this->serversConfig !== [] && !isset($this->serversConfig[$server])) {
                    return $this->handleValidationError(['server' => sprintf("Unknown server '%s'", $server)]);
                }

                // Validate granularity
                if (!$this->metricCatalog->allowsGranularity($granularityInput)) {
                    return $this->handleValidationError([
                        'granularity' => sprintf("Invalid granularity '%s'", $granularityInput),
                    ]);
                }

                // Validate range
                $rangeIsSpecial = in_array($range, ['all', 'max'], true);
                $rangeIsValid = $this->queryPolicy->parseRange($range) !== null;
                if ($range !== '' && !$rangeIsSpecial && !$rangeIsValid) {
                    return $this->handleValidationError(['range' => sprintf("Invalid range '%s'", $range)]);
                }

                // Normalize and validate each metric
                $normalizedMetrics = [];
                foreach ($metrics as $metric) {
                    $normalized = $this->queryPolicy->normalizeMetric($metric);
                    if (!$this->metricCatalog->supports($normalized)) {
                        return $this->handleValidationError(['metric' => sprintf("Unsupported metric '%s'", $metric)]);
                    }
                    $normalizedMetrics[] = $normalized;
                }

                try {
                    $granularity = $this->queryPolicy->resolveGranularity($range, $granularityInput);
                } catch (InvalidArgumentException $exception) {
                    return $this->handleValidationError(['granularity' => $exception->getMessage()]);
                }

                try {
                    $payload = $this->getMultiMetricSeriesPayload($server, $normalizedMetrics, $range, $granularity);
                } catch (InvalidArgumentException $exception) {
                    return $this->handleValidationError(['range' => $exception->getMessage()]);
                }

                $durationMs = round((microtime(true) - $requestStartTime) * 1000, 2);
                $dataPoints = isset($payload['series']['data']) && is_array($payload['series']['data'])
                    ? count($payload['series']['data'])
                    : 0;

                $this->logger->debug('metrics.request.complete', [
                    'endpoint' => 'getServerMultiMetricSeries',
                    'server' => $server,
                    'metrics' => implode(',', $normalizedMetrics),
                    'range' => $range,
                    'granularity' => $granularity,
                    'data_points' => $dataPoints,
                    'duration_ms' => $durationMs,
                    'from_cache' => $payload['cache']['from_cache'] ?? false,
                ]);

                return $this->metricsRenderer->renderMetricsJson(
                    $payload['series'],
                    $payload['filters'],
                    $payload['cache']
                );
            },
            'Failed to load multi-metric comparison data',
            "MetricsController:getServerMultiMetricSeries server={$server}"
        );
    }

    /**
     * @return list<array{value:string,label:string,granularity:string}>
     */
    private function serverMetricsModalRanges(): array
    {
        $ranges = [];
        foreach (self::SERVER_METRICS_MODAL_RANGES as $value => $label) {
            $ranges[] = [
                'value' => $value,
                'label' => $label,
                'granularity' => $this->queryPolicy->resolveGranularity($value, 'auto'),
            ];
        }

        return $ranges;
    }

    private function normalizeRangeInput(string $range): string
    {
        $normalized = strtolower(trim($range));

        return $normalized === '' ? '24h' : $normalized;
    }

    private function getQueryParam(string $key, string $default = ''): string
    {
        $value = $this->request->queryString($key);
        if ($value === null) {
            return $default;
        }

        $value = trim($value);

        return $value === '' ? $default : $value;
    }

    /**
     * @return array{
     *     filters: array<string,string>,
     *     series: array<string,mixed>,
     *     cache: array<string,mixed>
     * }
     */
    private function getSeriesPayload(
        string $server,
        string $metric,
        string $range,
        string $granularity
    ): array {
        $forceRefresh = in_array(strtolower($this->getQueryParam('force', '')), ['1', 'true', 'yes'], true);
        $cached = $forceRefresh ? null : $this->cacheService->readSeriesCache($server, $metric, $range, $granularity, $this->cacheTtlSeconds);
        if ($cached !== null) {
            return $cached;
        }

        $buildStartTime = microtime(true);

        $this->logger->debug('metrics.build.start', [
            'server' => $server,
            'metric' => $metric,
            'range' => $range,
            'granularity' => $granularity,
        ]);

        $series = $this->metricsViewBuilder->buildServerMetricTimeSeries(
            $server,
            $metric,
            $range,
            $granularity
        );

        $buildDurationMs = round((microtime(true) - $buildStartTime) * 1000, 2);
        $dataPoints = count($series['data']);

        $this->logger->debug('metrics.build.complete', [
            'server' => $server,
            'metric' => $metric,
            'range' => $range,
            'granularity' => $granularity,
            'data_points' => $dataPoints,
            'duration_ms' => $buildDurationMs,
        ]);

        $payload = [
            'filters' => $this->buildFilters($server, $metric, $range, $granularity),
            'series' => $series,
            'cache' => $this->cacheService->buildCacheMetadata(false),
        ];

        $this->cacheService->writeSeriesCache($server, $metric, $range, $granularity, $payload);

        return $payload;
    }

    /**
     * @param string[] $metrics
     *
     * @return array{
     *     filters: array<string,string>,
     *     series: array<string,mixed>,
     *     cache: array<string,mixed>
     * }
     */
    private function getMultiMetricSeriesPayload(
        string $server,
        array $metrics,
        string $range,
        string $granularity
    ): array {
        $metricsKey = implode(',', $metrics);
        $cached = $this->cacheService->readSeriesCache($server, $metricsKey, $range, $granularity, $this->cacheTtlSeconds);
        if ($cached !== null) {
            return $cached;
        }

        $buildStartTime = microtime(true);

        $this->logger->debug('metrics.build.start', [
            'server' => $server,
            'metrics' => $metricsKey,
            'range' => $range,
            'granularity' => $granularity,
        ]);

        $series = $this->metricsViewBuilder->buildMultiMetricTimeSeries(
            $server,
            $metrics,
            $range,
            $granularity
        );

        $buildDurationMs = round((microtime(true) - $buildStartTime) * 1000, 2);
        $dataPoints = count($series['labels']);

        $this->logger->debug('metrics.build.complete', [
            'server' => $server,
            'metrics' => $metricsKey,
            'range' => $range,
            'granularity' => $granularity,
            'data_points' => $dataPoints,
            'duration_ms' => $buildDurationMs,
        ]);

        $payload = [
            'filters' => $this->buildMultiMetricFilters($server, $metrics, $range, $granularity),
            'series' => $series,
            'cache' => $this->cacheService->buildCacheMetadata(false),
        ];

        $this->cacheService->writeSeriesCache($server, $metricsKey, $range, $granularity, $payload);

        return $payload;
    }

    /**
     * @return array<string,string>
     */
    private function buildFilters(
        string $server,
        string $metric,
        string $range,
        string $granularity
    ): array {
        return [
            'server' => $server,
            'metric' => $metric,
            'range' => $range,
            'granularity' => $granularity,
        ];
    }

    /**
     * @param string[] $metrics
     *
     * @return array<string,string>
     */
    private function buildMultiMetricFilters(
        string $server,
        array $metrics,
        string $range,
        string $granularity
    ): array {
        return [
            'server' => $server,
            'metrics' => implode(',', $metrics),
            'range' => $range,
            'granularity' => $granularity,
        ];
    }
}
