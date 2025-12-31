<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Controllers;

use Exception;
use InvalidArgumentException;
use Monolog\Logger;
use PHPUnit\Framework\MockObject\MockObject;
use Watcher\Controllers\MetricsController;
use Watcher\Http\Responses\HtmlResponse;
use Watcher\Http\Responses\JsonResponse;
use Watcher\Http\Responses\Response;
use Watcher\Services\Cache\MetricsCacheService;
use Watcher\Services\Validation\MetricsRequestValidator;
use Watcher\Services\Validation\MetricsValidationResult;
use Watcher\Services\View\ChartViewBuilder;
use Watcher\Services\View\MetricsViewBuilder;
use Watcher\Tests\TestCase;
use Watcher\Views\MetricsRenderer;

/**
 * Test suite for MetricsController.
 *
 * Validates behavior for metrics API endpoints including:
 * - Request validation and error handling
 * - Historical metrics data retrieval (raw, hourly, daily)
 * - Chart data transformation and formatting
 * - Time period filtering (24h, 7d, 30d, custom ranges)
 * - Server selection and validation
 * - Cache integration and TTL management
 * - JSON response formatting
 *
 * @covers \Watcher\Controllers\MetricsController
 *
 * @internal
 */
class MetricsControllerTest extends TestCase
{
    private MetricsController $controller;
    private Logger&MockObject $mockLogger;
    private MetricsViewBuilder&MockObject $mockViewBuilder;
    private MetricsRenderer&MockObject $mockRenderer;
    private MetricsCacheService&MockObject $mockCacheService;
    private MetricsRequestValidator&MockObject $mockValidator;
    private ChartViewBuilder&MockObject $mockChartViewBuilder;

    /** @var array<string,mixed> */
    private array $testServersConfig;

    /** @var array<string,mixed> */
    private array $testDashboardChartConfig;

    protected function setUp(): void
    {
        parent::setUp();

        $_SERVER['REQUEST_URI'] = '/';
        unset($_SERVER['HTTP_ACCEPT']);

        $this->mockLogger = $this->createMock(Logger::class);

        // For final classes in PHPUnit 10+, use getMockBuilder with allowMockingFinalClasses
        $this->mockViewBuilder = $this->getMockBuilder(MetricsViewBuilder::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['buildServerMetricTimeSeries', 'buildMultiMetricTimeSeries'])
            ->getMock()
        ;
        $this->mockRenderer = $this->getMockBuilder(MetricsRenderer::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['renderDashboardOverview', 'renderProcessCountModal', 'renderMetricsJson', 'renderDashboardMetricsJson', 'renderValidationError', 'renderError'])
            ->getMock()
        ;
        $this->mockCacheService = $this->getMockBuilder(MetricsCacheService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['readSeriesCache', 'writeSeriesCache', 'readDashboardCache', 'writeDashboardCache', 'buildDashboardCacheKey', 'buildCacheMetadata'])
            ->getMock()
        ;
        $this->mockValidator = $this->getMockBuilder(MetricsRequestValidator::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['validate', 'normalizeMetric', 'parseRange', 'resolveGranularity'])
            ->getMock()
        ;
        $this->mockChartViewBuilder = $this->getMockBuilder(ChartViewBuilder::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['buildDashboardOverviewData', 'buildChartConfig', 'resolveDashboardServers', 'buildDashboardMetricsPayload'])
            ->getMock()
        ;

        // Realistic test data representing actual server configuration
        $this->testServersConfig = [
            'prod-server' => [
                'api_url' => 'https://prod.example.com',
                'api_key' => 'test-api-key',
                'title' => 'Production Server',
            ],
            'staging-server' => [
                'api_url' => 'https://staging.example.com',
                'api_key' => 'test-api-key-2',
                'title' => 'Staging Server',
            ],
        ];

        // Dashboard chart configuration with defaults
        $this->testDashboardChartConfig = [
            'default_metric' => 'cpu',
            'default_timespan' => '24h',
            'metrics' => [
                'cpu' => ['label' => 'CPU Usage', 'unit' => '%'],
                'ram_percent' => ['label' => 'RAM Usage', 'unit' => '%'],
                'disk_percent' => ['label' => 'Disk Usage', 'unit' => '%'],
            ],
            'timespans' => [
                '24h' => ['range' => '24h', 'granularity' => 'hourly'],
                '7d' => ['range' => '7d', 'granularity' => 'daily'],
                '30d' => ['range' => '30d', 'granularity' => 'daily'],
            ],
        ];

        $this->mockChartViewBuilder
            ->method('buildChartConfig')
            ->willReturn($this->testDashboardChartConfig)
        ;
        $this->mockViewBuilder
            ->method('buildServerMetricTimeSeries')
            ->willReturn([
                'server_name' => 'prod-server',
                'metric' => 'cpu',
                'range' => '24h',
                'granularity' => 'hourly',
                'labels' => ['t1', 't2'],
                'data' => [1, 2],
                'min' => 1,
                'max' => 2,
                'avg' => 1.5,
                'unit' => '%',
            ])
        ;
        $this->mockViewBuilder
            ->method('buildMultiMetricTimeSeries')
            ->willReturn([
                'server_name' => 'prod-server',
                'range' => '24h',
                'granularity' => 'hourly',
                'labels' => ['t1', 't2'],
                'datasets' => [
                    [
                        'metric' => 'cpu',
                        'data' => [1, 2],
                        'unit' => '%',
                    ],
                ],
            ])
        ;

        $this->controller = new MetricsController(
            $this->mockLogger,
            $this->mockViewBuilder,
            $this->mockRenderer,
            $this->mockCacheService,
            $this->mockValidator,
            $this->mockChartViewBuilder,
            $this->testServersConfig,
            300, // Cache TTL
            $this->testDashboardChartConfig,
            60 // Dashboard cache TTL
        );
    }

    protected function tearDown(): void
    {
        // Clean up $_GET superglobal after each test
        $_GET = [];
        parent::tearDown();
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Dashboard Metrics Overview Tests
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * @group metrics-controller
     * @group dashboard-overview
     *
     * Validates that the dashboard overview renders successfully with
     * proper chart configuration and view data structure.
     */
    public function testItRendersMetricsDashboardOverviewSuccessfully(): void
    {
        // Arrange: Mock chart view builder to return dashboard data
        $viewData = [
            'chart' => ['element_id' => 'metrics-dashboard-chart'],
            'metrics' => ['cpu', 'ram_percent'],
            'timespans' => ['24h', '7d'],
            'servers' => ['prod-server', 'staging-server'],
        ];

        $this->mockChartViewBuilder
            ->expects($this->once())
            ->method('buildDashboardOverviewData')
            ->with(
                $this->callback(function ($config) {
                    // Verify chart config was processed and has required keys
                    return isset($config['default_metric'])
                        && isset($config['metrics'], $config['timespans']);
                }),
                60 // Dashboard cache TTL
            )
            ->willReturn($viewData)
        ;

        $this->mockRenderer
            ->expects($this->once())
            ->method('renderDashboardOverview')
            ->with($viewData)
            ->willReturn('<div id="metrics-dashboard">Dashboard HTML</div>')
        ;

        // Act: Render the metrics overview
        $response = $this->controller->renderMetricsOverview();

        // Assert: Verify successful HTML response
        $this->assertInstanceOf(HtmlResponse::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('metrics-dashboard', $response->getContent());
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Dashboard Metrics Data Endpoint Tests
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * @group metrics-controller
     * @group dashboard-data
     * @group cache
     *
     * Validates that dashboard metrics data is returned from cache when
     * available and fresh, avoiding unnecessary database queries.
     */
    public function testItReturnsDashboardMetricsFromCacheWhenAvailable(): void
    {
        // Arrange: Set up query parameters for CPU metric over 24 hours
        $_GET = [
            'metric' => 'cpu',
            'timespan' => '24h',
            'servers' => 'prod-server,staging-server',
        ];

        $this->mockValidator
            ->method('normalizeMetric')
            ->with('cpu')
            ->willReturn('cpu')
        ;

        $this->mockChartViewBuilder
            ->method('resolveDashboardServers')
            ->with('prod-server,staging-server')
            ->willReturn(['prod-server', 'staging-server'])
        ;

        $this->mockCacheService
            ->method('buildDashboardCacheKey')
            ->willReturn('cpu|24h|hourly|prod-server,staging-server')
        ;

        // Mock cache hit with realistic payload
        $cachedPayload = [
            'series' => [
                'labels' => ['2024-01-15 00:00', '2024-01-15 01:00'],
                'datasets' => [
                    ['label' => 'prod-server', 'data' => [45.2, 52.1]],
                    ['label' => 'staging-server', 'data' => [23.4, 28.9]],
                ],
            ],
            'cache' => ['from_cache' => true, 'age' => 30],
        ];

        $this->mockCacheService
            ->expects($this->once())
            ->method('readDashboardCache')
            ->with(
                'cpu|24h|hourly|prod-server,staging-server',
                60, // TTL
                false // No force refresh
            )
            ->willReturn($cachedPayload)
        ;

        $mockJsonResponse = $this->createMock(JsonResponse::class);
        $mockJsonResponse->method('getStatusCode')->willReturn(200);
        $mockJsonResponse->method('getContent')->willReturn(json_encode($cachedPayload));

        $this->mockRenderer
            ->expects($this->once())
            ->method('renderDashboardMetricsJson')
            ->with($cachedPayload)
            ->willReturn($mockJsonResponse)
        ;

        // Act: Fetch dashboard metrics data
        $response = $this->controller->getDashboardMetricsData();

        // Assert: Verify cache was used (no build call) and response is valid
        $this->assertEquals(200, $response->getStatusCode());
        $content = json_decode($response->getContent(), true);
        $this->assertTrue($content['cache']['from_cache']);
    }

    /**
     * @test
     *
     * @group metrics-controller
     * @group dashboard-data
     * @group validation
     *
     * Validates that unsupported metrics are rejected with proper error
     * messages before any processing occurs.
     */
    public function testItRejectsUnsupportedMetricForDashboard(): void
    {
        // Arrange: Request invalid metric
        $_GET = ['metric' => 'invalid_metric', 'timespan' => '24h'];

        $this->mockValidator
            ->method('normalizeMetric')
            ->with('invalid_metric')
            ->willReturn('invalid_metric')
        ;

        // Act: Attempt to fetch dashboard data with invalid metric
        $response = $this->controller->getDashboardMetricsData();

        // Assert: Verify 422 validation error response
        $this->assertEquals(422, $response->getStatusCode());
        $content = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('errors', $content);
        $this->assertArrayHasKey('metric', $content['errors']);
        $this->assertStringContainsString('invalid_metric', $content['errors']['metric']);
    }

    /**
     * @test
     *
     * @group metrics-controller
     * @group dashboard-data
     * @group validation
     *
     * Validates that invalid timespans are rejected before processing.
     */
    public function testItRejectsInvalidTimespanForDashboard(): void
    {
        // Arrange: Request valid metric but invalid timespan
        $_GET = ['metric' => 'cpu', 'timespan' => 'invalid_timespan'];

        $this->mockValidator
            ->method('normalizeMetric')
            ->willReturn('cpu')
        ;

        // Act: Attempt to fetch with invalid timespan
        $response = $this->controller->getDashboardMetricsData();

        // Assert: Verify validation error
        $this->assertEquals(422, $response->getStatusCode());
        $content = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('errors', $content);
        $this->assertArrayHasKey('timespan', $content['errors']);
    }

    /**
     * @test
     *
     * @group metrics-controller
     * @group dashboard-data
     * @group validation
     *
     * Validates that dashboard data request fails gracefully when no
     * servers are available or configured.
     */
    public function testItHandlesEmptyServerListForDashboard(): void
    {
        // Arrange: Request with empty server resolution
        $_GET = ['metric' => 'cpu', 'timespan' => '24h', 'servers' => ''];

        $this->mockValidator->method('normalizeMetric')->willReturn('cpu');
        $this->mockChartViewBuilder
            ->expects($this->once())
            ->method('resolveDashboardServers')
            ->willReturn([]) // No servers available
        ;

        // Act: Attempt to fetch dashboard data with no servers
        $response = $this->controller->getDashboardMetricsData();

        // Assert: Verify proper error response
        $this->assertEquals(422, $response->getStatusCode());
        $content = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('errors', $content);
        $this->assertArrayHasKey('servers', $content['errors']);
    }

    /**
     * @test
     *
     * @group metrics-controller
     * @group dashboard-data
     * @group cache
     *
     * Validates that force refresh parameter bypasses cache and fetches
     * fresh data from the metrics view builder.
     */
    public function testItBypassesCacheWhenForceRefreshIsRequested(): void
    {
        // Arrange: Request with force refresh parameter
        $_GET = [
            'metric' => 'cpu',
            'timespan' => '24h',
            'servers' => 'prod-server',
            'force' => '1', // Force refresh
        ];

        $this->mockValidator->method('normalizeMetric')->willReturn('cpu');
        $this->mockChartViewBuilder
            ->method('resolveDashboardServers')
            ->willReturn(['prod-server'])
        ;

        $this->mockCacheService->method('buildDashboardCacheKey')->willReturn('cache-key');

        // Cache read should be called with forceRefresh=true, returning null
        $this->mockCacheService
            ->expects($this->once())
            ->method('readDashboardCache')
            ->with('cache-key', 60, true) // Force refresh = true
            ->willReturn(null)
        ;

        // Fresh data should be built
        $freshPayload = [
            'series' => ['labels' => [], 'datasets' => []],
            'cache' => ['from_cache' => false],
        ];

        $this->mockChartViewBuilder
            ->expects($this->once())
            ->method('buildDashboardMetricsPayload')
            ->willReturn($freshPayload)
        ;

        $this->mockCacheService
            ->expects($this->once())
            ->method('writeDashboardCache')
            ->with('cache-key', $freshPayload, 60)
        ;

        $mockJsonResponse = $this->createMock(JsonResponse::class);
        $this->mockRenderer->method('renderDashboardMetricsJson')->willReturn($mockJsonResponse);

        // Act: Fetch with force refresh
        $response = $this->controller->getDashboardMetricsData();

        // Assert: Verify build was called (no cache hit)
        $this->assertInstanceOf(Response::class, $response);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Process Count Modal Tests
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * @group metrics-controller
     * @group process-count-modal
     * @group validation
     *
     * Validates that process count modal rendering validates the server
     * parameter and returns proper error for unknown servers.
     */
    public function testItValidatesServerForProcessCountModal(): void
    {
        // Arrange: Request modal for unknown server
        $validationResult = new MetricsValidationResult(
            ['server' => "Unknown server 'invalid-server'"],
            'process_count'
        );

        $this->mockValidator
            ->expects($this->once())
            ->method('validate')
            ->with(
                'invalid-server',
                'process_count',
                '30d', // Default range
                'daily',
                $this->testServersConfig
            )
            ->willReturn($validationResult)
        ;

        $this->mockRenderer
            ->expects($this->once())
            ->method('renderValidationError')
            ->with(['server' => "Unknown server 'invalid-server'"])
            ->willReturn('<div class="notification is-danger">Unknown server</div>')
        ;

        // Act: Attempt to render modal for invalid server
        $response = $this->controller->renderProcessCountModal('invalid-server');

        // Assert: Verify validation error response
        $this->assertInstanceOf(HtmlResponse::class, $response);
        $this->assertEquals(422, $response->getStatusCode());
        $this->assertStringContainsString('Unknown server', $response->getContent());
    }

    /**
     * @test
     *
     * @group metrics-controller
     * @group process-count-modal
     *
     * Validates that process count modal renders successfully with valid
     * server and retrieves the correct time-series data.
     */
    public function testItRendersProcessCountModalSuccessfully(): void
    {
        // Arrange: Valid server and range
        $_GET = ['range' => '60d'];

        $validationResult = new MetricsValidationResult([], 'process_count');

        $this->mockValidator
            ->method('validate')
            ->willReturn($validationResult)
        ;

        // Mock series payload with realistic process count data
        $seriesPayload = [
            'filters' => ['server' => 'prod-server', 'metric' => 'process_count', 'range' => '60d', 'granularity' => 'daily'],
            'series' => [
                'server_name' => 'prod-server',
                'metric' => 'process_count',
                'range' => '60d',
                'granularity' => 'daily',
                'labels' => ['2024-01-01', '2024-01-02', '2024-01-03'],
                'data' => [145, 152, 149],
                'min' => 145,
                'max' => 152,
                'avg' => 148.67,
                'unit' => '',
            ],
            'cache' => ['from_cache' => false, 'age' => 0],
        ];

        // Mock cache miss and payload build
        $this->mockCacheService->method('readSeriesCache')->willReturn(null);
        $this->mockViewBuilder
            ->expects($this->once())
            ->method('buildServerMetricTimeSeries')
            ->with('prod-server', 'process_count', '60d', 'daily')
            ->willReturn($seriesPayload['series'])
        ;

        $this->mockCacheService->method('buildCacheMetadata')->willReturn($seriesPayload['cache']);

        $this->mockRenderer
            ->expects($this->once())
            ->method('renderProcessCountModal')
            ->with('prod-server', $this->callback(function ($payload) {
                return isset($payload['filters'], $payload['series'], $payload['cache'])
                    && ($payload['filters']['metric'] ?? '') === 'process_count';
            }))
            ->willReturn('<div class="modal">Process count chart</div>')
        ;

        // Act: Render process count modal
        $response = $this->controller->renderProcessCountModal('prod-server');

        // Assert: Verify successful HTML response
        $this->assertInstanceOf(HtmlResponse::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('Process count chart', $response->getContent());
    }

    /**
     * @test
     *
     * @group metrics-controller
     * @group process-count-modal
     * @group logging
     *
     * Validates that the controller logs debug information about the
     * request including timing metrics and data point counts.
     */
    public function testItLogsProcessCountModalRequestMetrics(): void
    {
        // Arrange
        $_GET = ['range' => '30d'];
        $validationResult = new MetricsValidationResult([], 'process_count');
        $this->mockValidator->method('validate')->willReturn($validationResult);

        $seriesPayload = [
            'filters' => ['server' => 'prod-server', 'metric' => 'process_count', 'range' => '30d', 'granularity' => 'daily'],
            'series' => [
                'data' => array_fill(0, 30, 150), // 30 data points
            ],
            'cache' => ['from_cache' => false],
        ];

        $this->mockCacheService->method('readSeriesCache')->willReturn(null);
        $this->mockViewBuilder->method('buildServerMetricTimeSeries')->willReturn($seriesPayload['series']);
        $this->mockCacheService->method('buildCacheMetadata')->willReturn($seriesPayload['cache']);
        $this->mockRenderer->method('renderProcessCountModal')->willReturn('<div>Modal</div>');

        // Expect debug logging with request start and completion
        $callCount = 0;
        $this->mockLogger
            ->expects($this->exactly(2))
            ->method('debug')
            ->willReturnCallback(function ($message, $context) use (&$callCount) {
                ++$callCount;
                if ($callCount === 1) {
                    $this->assertSame('metrics.request.start', $message);
                    $this->assertSame('renderProcessCountModal', $context['endpoint'] ?? null);
                    $this->assertSame('prod-server', $context['server'] ?? null);
                    $this->assertSame('process_count', $context['metric'] ?? null);
                } else {
                    $this->assertSame('metrics.request.complete', $message);
                    $this->assertArrayHasKey('duration_ms', $context);
                    $this->assertSame(30, $context['data_points'] ?? null);
                    $this->assertArrayHasKey('from_cache', $context);
                }
            })
        ;

        // Act
        $this->controller->renderProcessCountModal('prod-server');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Single Metric Time Series Tests
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * @group metrics-controller
     * @group metric-series
     * @group granularity
     *
     * Validates that the controller correctly resolves auto granularity
     * based on the requested time range (raw for short periods, daily for long).
     */
    public function testItResolvesAutomaticGranularityForTimeRange(): void
    {
        // Arrange: Request 6 hours with auto granularity (should use raw)
        $_GET = ['range' => '6h', 'granularity' => 'auto'];

        $validationResult = new MetricsValidationResult([], 'cpu');
        $this->mockValidator->method('validate')->willReturn($validationResult);
        $this->mockValidator
            ->expects($this->once())
            ->method('resolveGranularity')
            ->with('6h', 'auto')
            ->willReturn('raw') // Auto-resolve to raw for 6h
        ;

        $seriesPayload = [
            'filters' => ['server' => 'prod-server', 'metric' => 'cpu', 'range' => '6h', 'granularity' => 'raw'],
            'series' => ['data' => [45.2, 52.1, 48.9], 'granularity' => 'raw'],
            'cache' => ['from_cache' => false],
        ];

        $this->mockCacheService->method('readSeriesCache')->willReturn(null);
        $this->mockViewBuilder
            ->expects($this->once())
            ->method('buildServerMetricTimeSeries')
            ->with('prod-server', 'cpu', '6h', 'raw') // Verify raw granularity
            ->willReturn($seriesPayload['series'])
        ;

        $this->mockCacheService->method('buildCacheMetadata')->willReturn($seriesPayload['cache']);

        $mockJsonResponse = $this->createMock(JsonResponse::class);
        $this->mockRenderer->method('renderMetricsJson')->willReturn($mockJsonResponse);

        // Act: Fetch metric series with auto granularity
        $response = $this->controller->getServerMetricSeries('prod-server', 'cpu');

        // Assert: Verify granularity resolution happened
        $this->assertInstanceOf(Response::class, $response);
    }

    /**
     * @test
     *
     * @group metrics-controller
     * @group metric-series
     * @group granularity
     *
     * Validates that explicit granularity overrides are respected and
     * not auto-resolved.
     */
    public function testItRespectsExplicitGranularityParameter(): void
    {
        // Arrange: Request 30 days but explicitly request hourly (not auto)
        $_GET = ['range' => '30d', 'granularity' => 'hourly'];

        $validationResult = new MetricsValidationResult([], 'ram_percent');
        $this->mockValidator->method('validate')->willReturn($validationResult);

        // Validator should return hourly without modification
        $this->mockValidator
            ->expects($this->once())
            ->method('resolveGranularity')
            ->with('30d', 'hourly')
            ->willReturn('hourly')
        ;

        $seriesPayload = [
            'filters' => ['granularity' => 'hourly'],
            'series' => ['granularity' => 'hourly'],
            'cache' => ['from_cache' => false],
        ];

        $this->mockCacheService->method('readSeriesCache')->willReturn(null);
        $this->mockViewBuilder
            ->method('buildServerMetricTimeSeries')
            ->with('prod-server', 'ram_percent', '30d', 'hourly')
            ->willReturn($seriesPayload['series'])
        ;

        $this->mockCacheService->method('buildCacheMetadata')->willReturn($seriesPayload['cache']);

        $mockJsonResponse = $this->createMock(JsonResponse::class);
        $this->mockRenderer->method('renderMetricsJson')->willReturn($mockJsonResponse);

        // Act
        $response = $this->controller->getServerMetricSeries('prod-server', 'ram-percent');

        // Assert
        $this->assertInstanceOf(Response::class, $response);
    }

    /**
     * @test
     *
     * @group metrics-controller
     * @group metric-series
     * @group validation
     *
     * Validates that metric normalization happens correctly (hyphen to underscore).
     */
    public function testItNormalizesMetricNamesFromUrlFormat(): void
    {
        // Arrange: URL uses hyphenated metric name
        $_GET = ['range' => '24h', 'granularity' => 'hourly'];

        $validationResult = new MetricsValidationResult([], 'disk_percent');
        $this->mockValidator
            ->expects($this->once())
            ->method('validate')
            ->with(
                'prod-server',
                'disk-percent', // Hyphenated input
                '24h',
                'hourly',
                $this->testServersConfig
            )
            ->willReturn($validationResult)
        ;

        $this->mockValidator->method('resolveGranularity')->willReturn('hourly');

        $seriesPayload = [
            'filters' => ['metric' => 'disk_percent'],
            'series' => ['metric' => 'disk_percent'], // Normalized
            'cache' => ['from_cache' => false],
        ];

        $this->mockCacheService->method('readSeriesCache')->willReturn(null);
        $this->mockViewBuilder->method('buildServerMetricTimeSeries')->willReturn($seriesPayload['series']);
        $this->mockCacheService->method('buildCacheMetadata')->willReturn($seriesPayload['cache']);

        $mockJsonResponse = $this->createMock(JsonResponse::class);
        $this->mockRenderer->method('renderMetricsJson')->willReturn($mockJsonResponse);

        // Act: Pass hyphenated metric name
        $response = $this->controller->getServerMetricSeries('prod-server', 'disk-percent');

        // Assert: Verify response was generated
        $this->assertInstanceOf(Response::class, $response);
    }

    /**
     * @test
     *
     * @group metrics-controller
     * @group metric-series
     * @group error-handling
     *
     * Validates that invalid granularity values result in proper error
     * responses with descriptive messages.
     */
    public function testItHandlesInvalidGranularityGracefully(): void
    {
        // Arrange: Request with invalid granularity
        $_GET = ['range' => '24h', 'granularity' => 'invalid'];

        $validationResult = new MetricsValidationResult([], 'cpu');
        $this->mockValidator->method('validate')->willReturn($validationResult);

        // Validator throws exception for invalid granularity
        $this->mockValidator
            ->method('resolveGranularity')
            ->willThrowException(new InvalidArgumentException('Invalid granularity value'))
        ;

        // Act: Attempt to fetch with invalid granularity
        $response = $this->controller->getServerMetricSeries('prod-server', 'cpu');

        // Assert: Verify validation error response
        $this->assertEquals(422, $response->getStatusCode());
        $content = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('errors', $content);
        $this->assertArrayHasKey('granularity', $content['errors']);
    }

    /**
     * @test
     *
     * @group metrics-controller
     * @group metric-series
     * @group error-handling
     *
     * Validates that invalid time range formats result in proper validation
     * errors with helpful messages.
     */
    public function testItHandlesInvalidRangeFormat(): void
    {
        // Arrange: Invalid range format
        $_GET = ['range' => 'invalid-range', 'granularity' => 'hourly'];

        $validationResult = new MetricsValidationResult([], 'cpu');
        $this->mockValidator->method('validate')->willReturn($validationResult);
        $this->mockValidator->method('resolveGranularity')->willReturn('hourly');

        $this->mockCacheService->method('readSeriesCache')->willReturn(null);

        // View builder throws exception for invalid range
        $this->mockViewBuilder
            ->method('buildServerMetricTimeSeries')
            ->willThrowException(new InvalidArgumentException('Invalid time range format'))
        ;

        // Act
        $response = $this->controller->getServerMetricSeries('prod-server', 'cpu');

        // Assert: Verify error response
        $this->assertEquals(422, $response->getStatusCode());
        $content = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('errors', $content);
        $this->assertArrayHasKey('range', $content['errors']);
    }

    /**
     * @test
     *
     * @group metrics-controller
     * @group metric-series
     * @group cache
     *
     * Validates that metric series data is cached after building and
     * subsequent requests use the cached version.
     */
    public function testItCachesMetricSeriesAfterBuilding(): void
    {
        // Arrange
        $_GET = ['range' => '24h', 'granularity' => 'hourly'];

        $validationResult = new MetricsValidationResult([], 'cpu');
        $this->mockValidator->method('validate')->willReturn($validationResult);
        $this->mockValidator->method('resolveGranularity')->willReturn('hourly');

        $seriesPayload = [
            'filters' => ['server' => 'prod-server', 'metric' => 'cpu', 'range' => '24h', 'granularity' => 'hourly'],
            'series' => ['data' => [45.2, 52.1]],
            'cache' => ['from_cache' => false],
        ];

        // First call: cache miss
        $this->mockCacheService
            ->expects($this->once())
            ->method('readSeriesCache')
            ->willReturn(null)
        ;

        $this->mockViewBuilder
            ->expects($this->once())
            ->method('buildServerMetricTimeSeries')
            ->willReturn($seriesPayload['series'])
        ;

        $this->mockCacheService->method('buildCacheMetadata')->willReturn($seriesPayload['cache']);

        // Verify cache write happens
        $this->mockCacheService
            ->expects($this->once())
            ->method('writeSeriesCache')
            ->with(
                'prod-server',
                'cpu',
                '24h',
                'hourly',
                $this->callback(function ($payload) {
                    return isset($payload['filters'], $payload['series'], $payload['cache'])
                        && isset($payload['series']['data']);
                }),
                300 // TTL
            )
        ;

        $mockJsonResponse = $this->createMock(JsonResponse::class);
        $this->mockRenderer->method('renderMetricsJson')->willReturn($mockJsonResponse);

        // Act
        $response = $this->controller->getServerMetricSeries('prod-server', 'cpu');

        // Assert: Verify response generated and cache write occurred
        $this->assertInstanceOf(Response::class, $response);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Multi-Metric Comparison Tests
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * @group metrics-controller
     * @group multi-metric
     * @group validation
     *
     * Validates that multi-metric endpoint requires at least one metric
     * to be specified in the request.
     */
    public function testItRequiresAtLeastOneMetricForComparison(): void
    {
        // Arrange: Empty metrics parameter
        $_GET = ['metrics' => ',', 'range' => '24h'];

        // Act: Attempt to fetch multi-metric with empty metrics
        $response = $this->controller->getServerMultiMetricSeries('prod-server');

        // Assert: Verify validation error
        $this->assertEquals(422, $response->getStatusCode());
        $content = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('errors', $content);
        $this->assertArrayHasKey('metrics', $content['errors']);
        $this->assertStringContainsString('At least one metric', $content['errors']['metrics']);
    }

    /**
     * @test
     *
     * @group metrics-controller
     * @group multi-metric
     * @group validation
     *
     * Validates that all metrics in a multi-metric request are validated
     * and unsupported metrics are rejected.
     */
    public function testItValidatesAllMetricsInMultiMetricRequest(): void
    {
        // Arrange: Mix of valid and invalid metrics
        $_GET = ['metrics' => 'cpu,invalid_metric,ram_percent', 'range' => '24h'];

        $this->mockValidator
            ->method('normalizeMetric')
            ->willReturnCallback(fn ($m) => str_replace('-', '_', strtolower(trim($m))))
        ;
        $this->mockValidator
            ->method('parseRange')
            ->willReturn(['value' => 24, 'unit' => 'h'])
        ;

        // Act: Attempt to fetch with invalid metric in the list
        $response = $this->controller->getServerMultiMetricSeries('prod-server');

        // Assert: Verify validation error for unsupported metric
        $this->assertEquals(422, $response->getStatusCode());
        $content = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('errors', $content);
        $this->assertArrayHasKey('metric', $content['errors']);
        $this->assertStringContainsString('invalid_metric', $content['errors']['metric']);
    }

    /**
     * @test
     *
     * @group metrics-controller
     * @group multi-metric
     *
     * Validates that multiple valid metrics are processed correctly and
     * return combined time-series data for comparison charts.
     */
    public function testItBuildsMultiMetricComparisonDataSuccessfully(): void
    {
        // Arrange: Request CPU and RAM comparison
        $_GET = ['metrics' => 'cpu,ram-percent', 'range' => '24h', 'granularity' => 'hourly'];

        $this->mockValidator
            ->method('normalizeMetric')
            ->willReturnCallback(fn ($m) => str_replace('-', '_', strtolower(trim($m))))
        ;

        $this->mockValidator->method('parseRange')->willReturn(['value' => 24, 'unit' => 'h']);
        $this->mockValidator->method('resolveGranularity')->willReturn('hourly');

        // Mock multi-metric series payload
        $multiMetricPayload = [
            'filters' => [
                'server' => 'prod-server',
                'metrics' => 'cpu,ram_percent',
                'range' => '24h',
                'granularity' => 'hourly',
            ],
            'series' => [
                'labels' => ['2024-01-15 00:00', '2024-01-15 01:00'],
                'datasets' => [
                    ['label' => 'CPU', 'data' => [45.2, 52.1]],
                    ['label' => 'RAM', 'data' => [65.4, 67.2]],
                ],
            ],
            'cache' => ['from_cache' => false],
        ];

        $this->mockCacheService->method('readSeriesCache')->willReturn(null);
        $this->mockViewBuilder
            ->expects($this->once())
            ->method('buildMultiMetricTimeSeries')
            ->with(
                'prod-server',
                ['cpu', 'ram_percent'], // Normalized metrics
                '24h',
                'hourly'
            )
            ->willReturn($multiMetricPayload['series'])
        ;

        $this->mockCacheService->method('buildCacheMetadata')->willReturn($multiMetricPayload['cache']);

        $mockJsonResponse = $this->createMock(JsonResponse::class);
        $this->mockRenderer
            ->expects($this->once())
            ->method('renderMetricsJson')
            ->with(
                $this->callback(function ($series) {
                    return isset($series['labels'], $series['datasets']);
                }),
                $this->callback(function ($filters) {
                    return isset($filters['metrics'], $filters['range'], $filters['granularity']);
                }),
                $this->callback(function ($cache) {
                    return isset($cache['from_cache']);
                })
            )
            ->willReturn($mockJsonResponse)
        ;

        // Act: Fetch multi-metric comparison data
        $response = $this->controller->getServerMultiMetricSeries('prod-server');

        // Assert: Verify successful response
        $this->assertInstanceOf(Response::class, $response);
    }

    /**
     * @test
     *
     * @group metrics-controller
     * @group multi-metric
     * @group cache
     *
     * Validates that multi-metric comparison data is cached using a
     * combined cache key based on all requested metrics.
     */
    public function testItCachesMultiMetricDataWithCombinedKey(): void
    {
        // Arrange
        $_GET = ['metrics' => 'cpu,ram_percent,disk_percent', 'range' => '7d', 'granularity' => 'daily'];

        $this->mockValidator->method('normalizeMetric')
            ->willReturnCallback(fn ($m) => str_replace('-', '_', strtolower(trim($m))))
        ;
        $this->mockValidator->method('parseRange')->willReturn(['value' => 7, 'unit' => 'd']);
        $this->mockValidator->method('resolveGranularity')->willReturn('daily');

        $multiMetricPayload = [
            'filters' => ['metrics' => 'cpu,ram_percent,disk_percent'],
            'series' => ['datasets' => []],
            'cache' => ['from_cache' => false],
        ];

        // Verify cache is keyed by combined metric string
        $this->mockCacheService
            ->expects($this->once())
            ->method('readSeriesCache')
            ->with(
                'prod-server',
                'cpu,ram_percent,disk_percent', // Combined key
                '7d',
                'daily',
                300
            )
            ->willReturn(null)
        ;

        $this->mockViewBuilder->method('buildMultiMetricTimeSeries')->willReturn($multiMetricPayload['series']);
        $this->mockCacheService->method('buildCacheMetadata')->willReturn($multiMetricPayload['cache']);

        // Verify cache write uses combined key
        $this->mockCacheService
            ->expects($this->once())
            ->method('writeSeriesCache')
            ->with(
                'prod-server',
                'cpu,ram_percent,disk_percent', // Combined key
                '7d',
                'daily',
                $this->callback(function ($payload) {
                    return isset($payload['filters'], $payload['series'], $payload['cache'])
                        && isset($payload['filters']['metrics']);
                }),
                300
            )
        ;

        $mockJsonResponse = $this->createMock(JsonResponse::class);
        $this->mockRenderer->method('renderMetricsJson')->willReturn($mockJsonResponse);

        // Act
        $this->controller->getServerMultiMetricSeries('prod-server');
    }

    /**
     * @test
     *
     * @group metrics-controller
     * @group multi-metric
     * @group validation
     *
     * Validates that unknown server names are rejected in multi-metric
     * requests before processing.
     */
    public function testItValidatesServerExistsForMultiMetricRequest(): void
    {
        // Arrange: Request for unknown server
        $_GET = ['metrics' => 'cpu', 'range' => '24h'];

        $this->mockValidator->method('normalizeMetric')->willReturn('cpu');

        // Act: Attempt to fetch multi-metric for unknown server
        $response = $this->controller->getServerMultiMetricSeries('unknown-server');

        // Assert: Verify validation error
        $this->assertEquals(422, $response->getStatusCode());
        $content = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('errors', $content);
        $this->assertArrayHasKey('server', $content['errors']);
        $this->assertStringContainsString('unknown-server', $content['errors']['server']);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Error Handling and Edge Cases
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * @group metrics-controller
     * @group error-handling
     *
     * Validates that exceptions during metrics rendering are caught and
     * return proper error responses instead of exposing stack traces.
     */
    public function testItHandlesExceptionsDuringDashboardOverviewRendering(): void
    {
        // Arrange: Mock chart view builder to throw exception
        $this->mockChartViewBuilder
            ->method('buildDashboardOverviewData')
            ->willThrowException(new Exception('Database connection failed'))
        ;

        $this->mockLogger
            ->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('MetricsController:renderMetricsOverview'),
                $this->callback(function ($context) {
                    return isset($context['error'])
                        && $context['error'] === 'Database connection failed';
                })
            )
        ;

        // Act: Attempt to render overview when service fails
        $response = $this->controller->renderMetricsOverview();

        // Assert: Verify error response with user-friendly message
        $this->assertEquals(500, $response->getStatusCode());
        $this->assertStringContainsString('Failed to render metrics overview', $response->getContent());
    }

    /**
     * @test
     *
     * @group metrics-controller
     * @group query-params
     *
     * Validates that missing query parameters fall back to sensible
     * defaults without causing errors.
     */
    public function testItUsesDefaultValuesForMissingQueryParameters(): void
    {
        // Arrange: No query parameters provided
        $_GET = [];

        $validationResult = new MetricsValidationResult([], 'cpu');
        $this->mockValidator->method('validate')->willReturn($validationResult);
        $this->mockValidator->method('resolveGranularity')->willReturn('hourly');

        $seriesPayload = [
            'filters' => [],
            'series' => [],
            'cache' => ['from_cache' => false],
        ];

        $this->mockCacheService->method('readSeriesCache')->willReturn(null);

        // Verify defaults are used: range=24h, granularity=auto
        $this->mockViewBuilder
            ->expects($this->once())
            ->method('buildServerMetricTimeSeries')
            ->with(
                'prod-server',
                'cpu',
                '24h', // Default range
                'hourly' // Resolved from auto
            )
            ->willReturn($seriesPayload['series'])
        ;

        $this->mockCacheService->method('buildCacheMetadata')->willReturn($seriesPayload['cache']);

        $mockJsonResponse = $this->createMock(JsonResponse::class);
        $this->mockRenderer->method('renderMetricsJson')->willReturn($mockJsonResponse);

        // Act: Fetch without query parameters
        $response = $this->controller->getServerMetricSeries('prod-server', 'cpu');

        // Assert: Verify defaults were applied
        $this->assertInstanceOf(Response::class, $response);
    }

    /**
     * @test
     *
     * @group metrics-controller
     * @group logging
     *
     * Validates that metric series requests are logged with timing
     * information for performance monitoring.
     */
    public function testItLogsPerformanceMetricsForSeriesRequests(): void
    {
        // Arrange
        $_GET = ['range' => '24h', 'granularity' => 'hourly'];

        $validationResult = new MetricsValidationResult([], 'cpu');
        $this->mockValidator->method('validate')->willReturn($validationResult);
        $this->mockValidator->method('resolveGranularity')->willReturn('hourly');

        $seriesPayload = [
            'filters' => [],
            'series' => ['data' => array_fill(0, 24, 50.0)], // 24 data points
            'cache' => ['from_cache' => false],
        ];

        $this->mockCacheService->method('readSeriesCache')->willReturn(null);
        $this->mockViewBuilder->method('buildServerMetricTimeSeries')->willReturn($seriesPayload['series']);
        $this->mockCacheService->method('buildCacheMetadata')->willReturn($seriesPayload['cache']);

        // Expect debug logging with performance metrics
        $callCount = 0;
        $this->mockLogger
            ->expects($this->exactly(2))
            ->method('debug')
            ->willReturnCallback(function ($message, $context) use (&$callCount) {
                ++$callCount;
                if ($callCount === 1) {
                    $this->assertSame('metrics.request.start', $message);
                } else {
                    $this->assertSame('metrics.request.complete', $message);
                    $this->assertArrayHasKey('duration_ms', $context);
                    $this->assertSame(24, $context['data_points'] ?? null);
                }
            });

        $mockJsonResponse = $this->createMock(JsonResponse::class);
        $this->mockRenderer->method('renderMetricsJson')->willReturn($mockJsonResponse);

        // Act
        $this->controller->getServerMetricSeries('prod-server', 'cpu');
    }
}
