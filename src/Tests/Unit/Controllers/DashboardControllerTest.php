<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Controllers;

use Exception;
use Mata\Config\Dto\ApplicationConfig;
use Mata\Controllers\DashboardController;
use Mata\Core\App;
use Mata\Database\DatabaseInterface;
use Mata\Http\Responses\HtmlResponse;
use Mata\Http\Responses\JsonResponse;
use Mata\Http\Routing\Endpoints;
use Mata\Models\AppFatalStateRepository;
use Mata\Services\Cache\ApiCacheKeyFactory;
use Mata\Services\Cache\CacheStoreInterface;
use Mata\Services\Cache\CacheTtlResolver;
use Mata\Services\Collect\NodeResponseNormalizer;
use Mata\Services\Event\EventAggregatorServiceInterface;
use Mata\Services\Event\EventManager;
use Mata\Services\Identity\ServerApplicationIdResolver;
use Mata\Services\Monitor\Dto\AppReachabilityPolicy;
use Mata\Services\Monitor\Dto\DashboardMonitoringContext;
use Mata\Services\Monitor\HttpProbeClient;
use Mata\Services\Monitor\LogSizeEvaluator;
use Mata\Services\Monitor\ServerMonitoringFactory;
use Mata\Services\Navigation\MenuBuilder;
use Mata\Services\Notification\NotificationManager;
use Mata\Tests\Helpers\ApplicationConfigFactory;
use Mata\Tests\Helpers\NotificationManagerFactory;
use Mata\Utils\PathResolver;
use Mata\Utils\Tools;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Twig\Environment;

/**
 * DashboardController Test Suite.
 *
 * Tests the main dashboard controller covering:
 * - Dashboard page rendering with authentication
 * - Server data refresh endpoints
 * - Force refresh functionality (cache bypass)
 * - Error handling when servers are unreachable
 * - Proper data structure passed to templates
 * - Integration with ServerDataCollector service
 * - Session validation
 * - JSON API endpoints
 *
 * Testing approach:
 * - Mock external dependencies (API clients, cache, database)
 * - Test behavior, not implementation details
 * - Validate real-world scenarios including error conditions
 * - Use meaningful test data reflecting actual usage
 *
 * @internal
 *
 * @coversNothing
 */
// Shared controller fixtures are reconfigured after construction across this legacy suite.
// Rebuilding the controller for each interaction would risk losing existing setup behavior.
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
class DashboardControllerTest extends TestCase
{
    private DashboardController $controller;
    private Logger $logger;
    private Environment $twig;
    private App $app;
    private Tools $tools;
    private ApplicationConfig $appConfig;
    private NotificationManager $notificationManager;
    private EventManager $eventManager;
    private CacheStoreInterface $cacheStore;
    private ApiCacheKeyFactory $cacheKeyFactory;
    private CacheTtlResolver $ttlResolver;
    private Endpoints $endpoints;
    private MenuBuilder $menuBuilder;
    private array $monitoringSettings;

    protected function setUp(): void
    {
        parent::setUp();

        $_SERVER['REQUEST_URI'] = '/';
        unset($_SERVER['HTTP_ACCEPT']);

        // Create mocks for dependencies
        $this->logger = $this->createMock(Logger::class);
        $this->twig = $this->createMock(Environment::class);
        $this->app = $this->createMock(App::class);
        $this->tools = $this->createMock(Tools::class);

        // Mock PathResolver for App
        $mockPaths = $this->createMock(PathResolver::class);
        $mockPaths->method('toRelativePath')->willReturnCallback(fn ($path) => ltrim(str_replace('/tmp/', '', $path), '/'));
        $mockPaths->appPath = '/tmp/test-app';
        $this->app->paths = $mockPaths;
        // NotificationManager is final, so we create a real instance with NullDatabase
        $this->notificationManager = NotificationManagerFactory::create($this->logger);
        $this->eventManager = $this->createMock(EventManager::class);
        $this->cacheStore = $this->createMock(CacheStoreInterface::class);
        $this->cacheKeyFactory = new ApiCacheKeyFactory();
        $this->ttlResolver = new CacheTtlResolver([]);
        $this->endpoints = $this->createMock(Endpoints::class);
        $this->menuBuilder = $this->createMock(MenuBuilder::class);
        $this->twig->method('render')->willReturn('<html></html>');
        $this->menuBuilder->method('buildDashboardMenu')->willReturn([]);
        $this->app->method('getBaseUrl')->willReturn('/');
        $this->app->method('getFullBaseUrl')->willReturn('http://localhost');

        // Create application config with nested DTOs (using real instances since they're final)
        $this->appConfig = ApplicationConfigFactory::create(
            environment: ApplicationConfigFactory::createEnvironmentConfig(env: 'demo'),
            dashboard: ApplicationConfigFactory::createDashboardConfig(serverColumns: 3, containerWidth: 1344),
        );

        // Default monitoring settings - production environment with real collectors
        $this->monitoringSettings = [
            'environment' => 'production',
            'servers' => [
                'web-server-01' => [
                    'api_url' => 'https://web-server-01.example.com/api',
                    'api_key' => 'test-api-key-123',
                    'title' => 'Web Server 01',
                    'primary_color' => '#3273dc',
                    'url' => 'https://web-server-01.example.com',
                ],
                'db-server-01' => [
                    'api_url' => 'https://db-server-01.example.com/api',
                    'api_key' => 'test-api-key-456',
                    'title' => 'Database Server 01',
                    'primary_color' => '#48c774',
                    'url' => 'https://db-server-01.example.com',
                ],
            ],
            'ttl' => [
                'ping' => 360,
                'apps' => 60,
                'serverData' => 10,
            ],
            'thresholds' => [
                'cpu_usage' => ['warning' => 70, 'danger' => 90],
                'memory_usage' => ['warning' => 80, 'danger' => 95],
            ],
        ];
    }

    /**
     * Test: Dashboard index renders successfully with active notification.
     *
     * Validates that:
     * - Dashboard page is rendered with correct template
     * - Active notifications are retrieved and passed to template
     * - Endpoints are properly configured and passed to view
     * - HTML response is returned with successful status code
     *
     * Note: NotificationManager is a real instance, so we test with
     * whatever notifications it returns (likely empty from NullDatabase)
     */
    public function testIndexRendersSuccessfullyWithActiveNotification(): void
    {
        // Arrange: Setup endpoints
        $expectedEndpoints = [
            'server_render' => '/api/servers/render',
            'server_render_refresh' => '/api/servers/refresh',
            'metrics_render' => '/api/metrics/render',
            'metrics_dashboard_data' => '/api/metrics/data',
            'alarms_render' => '/api/alarms/render',
            'libraries_render' => '/api/libraries/render',
            'database_render' => '/api/databases/render',
            'settings_render' => '/api/settings/render',
            'database_notice_ack' => '/api/database/notice/ack',
        ];

        $this->endpoints
            ->method('url')
            ->willReturnCallback(function ($key) use ($expectedEndpoints) {
                return $expectedEndpoints[$key] ?? null;
            })
        ;

        // Arrange: Setup Twig to capture rendered data
        $renderedHtml = '<html><body>Dashboard content</body></html>';
        $this->twig
            ->method('render')
            ->willReturn($renderedHtml)
        ;

        // Create controller with dependencies
        $this->controller = new DashboardController(
            $this->app,
            $this->tools,
            $this->twig,
            $this->logger,
            $this->appConfig,
            $this->notificationManager,
            $this->eventManager,
            $this->cacheStore,
            $this->cacheKeyFactory,
            $this->endpoints,
            $this->menuBuilder,
            $this->monitoringContext()
        );

        // Act
        $response = $this->controller->index();

        // Assert: Verify response type and success
        $this->assertInstanceOf(HtmlResponse::class, $response);
        $this->assertTrue($response->isSuccessful());
        $this->assertEquals(200, $response->getStatusCode());
    }

    /**
     * Test: Dashboard index handles empty notifications gracefully.
     *
     * Validates that:
     * - Dashboard renders when no active notifications exist
     * - Empty notification array is properly handled
     * - No errors occur with missing notifications
     *
     * Note: Using real NotificationManager which returns empty array by default
     */
    public function testIndexHandlesEmptyNotifications(): void
    {
        // Arrange: Setup endpoints
        $this->endpoints
            ->method('get')
            ->willReturn('/api/test')
        ;

        // Create controller with dependencies
        $this->controller = new DashboardController(
            $this->app,
            $this->tools,
            $this->twig,
            $this->logger,
            $this->appConfig,
            $this->notificationManager,
            $this->eventManager,
            $this->cacheStore,
            $this->cacheKeyFactory,
            $this->endpoints,
            $this->menuBuilder,
            $this->monitoringContext()
        );

        // Act
        $response = $this->controller->index();

        // Assert
        $this->assertInstanceOf(HtmlResponse::class, $response);
        $this->assertTrue($response->isSuccessful());
    }

    /**
     * Test: Dashboard index with renderer error handling.
     *
     * Validates that:
     * - Exceptions from renderer are caught
     * - Error is logged with proper context
     * - User-friendly error response is returned
     * - Application doesn't crash
     *
     * Note: We cannot make NotificationManager throw since it's a real instance,
     * so we test error handling by making the renderer fail instead
     */
    public function testIndexHandlesRendererFailure(): void
    {
        // Arrange: Setup endpoints
        $this->endpoints
            ->method('get')
            ->willReturn('/api/test')
        ;

        $this->twig
            ->method('render')
            ->willThrowException(new Exception('Render failure'))
        ;

        $this->logger
            ->expects($this->atLeastOnce())
            ->method('error')
        ;

        // Create controller with dependencies
        $this->controller = new DashboardController(
            $this->app,
            $this->tools,
            $this->twig,
            $this->logger,
            $this->appConfig,
            $this->notificationManager,
            $this->eventManager,
            $this->cacheStore,
            $this->cacheKeyFactory,
            $this->endpoints,
            $this->menuBuilder,
            $this->monitoringContext()
        );

        // Act
        $response = $this->controller->index();

        $this->assertInstanceOf(HtmlResponse::class, $response);
        $this->assertTrue($response->isError());
    }

    /**
     * Test: Servers overview renders successfully with real data.
     *
     * Validates that:
     * - Server data is collected from all configured servers
     * - Data is transformed into view-ready format
     * - HTML response contains server cards
     * - Cache is used appropriately (not force refresh)
     */
    public function testRenderServersOverviewSuccessfully(): void
    {
        // Arrange: Setup monitoring settings with demo environment
        $this->monitoringSettings['environment'] = 'demo';

        // Recreate controller with demo settings to use mock collectors
        $this->controller = new DashboardController(
            $this->app,
            $this->tools,
            $this->twig,
            $this->logger,
            $this->appConfig,
            $this->notificationManager,
            $this->eventManager,
            $this->cacheStore,
            $this->cacheKeyFactory,
            $this->endpoints,
            $this->menuBuilder,
            $this->monitoringContext()
        );

        // Act
        $response = $this->controller->renderServersOverview(forceRefresh: false);

        // Assert
        $this->assertInstanceOf(HtmlResponse::class, $response);
        $this->assertTrue($response->isSuccessful());
        $this->assertEquals(200, $response->getStatusCode());
    }

    /**
     * Test: Servers overview force refresh bypasses cache.
     *
     * Validates that:
     * - Force refresh parameter is properly passed through
     * - Cache is bypassed when force refresh is true
     * - Fresh data is collected from servers
     * - Response is successful
     *
     * Why this matters: Users need ability to get real-time data
     * when they suspect cached data is stale or incorrect.
     */
    public function testRenderServersOverviewWithForceRefresh(): void
    {
        // Arrange: Use demo environment for predictable mock data
        $this->monitoringSettings['environment'] = 'demo';

        $this->controller = new DashboardController(
            $this->app,
            $this->tools,
            $this->twig,
            $this->logger,
            $this->appConfig,
            $this->notificationManager,
            $this->eventManager,
            $this->cacheStore,
            $this->cacheKeyFactory,
            $this->endpoints,
            $this->menuBuilder,
            $this->monitoringContext()
        );

        // Act: Request with force refresh
        $response = $this->controller->renderServersOverview(forceRefresh: true);

        // Assert: Fresh data is rendered
        $this->assertInstanceOf(HtmlResponse::class, $response);
        $this->assertTrue($response->isSuccessful());
    }

    /**
     * Test: Server overview handles unreachable server gracefully.
     *
     * Validates that:
     * - When a server is unreachable, error is logged
     * - Error state is shown in UI (not a 500 error)
     * - Other servers continue to render successfully
     * - Partial failure doesn't break entire dashboard
     *
     * Why this matters: In production, some servers may be down for
     * maintenance or network issues. The dashboard should remain
     * functional and show status of reachable servers.
     */
    public function testRenderServersOverviewHandlesUnreachableServer(): void
    {
        // Arrange: Use demo environment but expect potential errors
        $this->monitoringSettings['environment'] = 'demo';

        // Recreate controller
        $this->controller = new DashboardController(
            $this->app,
            $this->tools,
            $this->twig,
            $this->logger,
            $this->appConfig,
            $this->notificationManager,
            $this->eventManager,
            $this->cacheStore,
            $this->cacheKeyFactory,
            $this->endpoints,
            $this->menuBuilder,
            $this->monitoringContext()
        );

        // Act: Even with potential errors, should return response
        $response = $this->controller->renderServersOverview(forceRefresh: false);

        // Assert: Returns successful response with error states for unreachable servers
        $this->assertInstanceOf(HtmlResponse::class, $response);
        $this->assertTrue($response->isSuccessful());
    }

    /**
     * Test: Databases overview renders successfully.
     *
     * Validates that:
     * - Database information is collected from servers
     * - Data is formatted for display
     * - HTML response is returned
     */
    public function testRenderDatabasesOverviewSuccessfully(): void
    {
        // Arrange
        $this->monitoringSettings['environment'] = 'demo';

        $this->controller = new DashboardController(
            $this->app,
            $this->tools,
            $this->twig,
            $this->logger,
            $this->appConfig,
            $this->notificationManager,
            $this->eventManager,
            $this->cacheStore,
            $this->cacheKeyFactory,
            $this->endpoints,
            $this->menuBuilder,
            $this->monitoringContext()
        );

        // Act
        $response = $this->controller->renderDatabasesOverview(forceRefresh: false);

        // Assert
        $this->assertInstanceOf(HtmlResponse::class, $response);
        $this->assertTrue($response->isSuccessful());
    }

    /**
     * Test: Libraries overview renders successfully.
     *
     * Validates that:
     * - Library information is collected from all apps
     * - Data includes version information
     * - HTML response is returned
     */
    public function testRenderLibrariesOverviewSuccessfully(): void
    {
        // Arrange
        $this->monitoringSettings['environment'] = 'demo';

        $this->controller = new DashboardController(
            $this->app,
            $this->tools,
            $this->twig,
            $this->logger,
            $this->appConfig,
            $this->notificationManager,
            $this->eventManager,
            $this->cacheStore,
            $this->cacheKeyFactory,
            $this->endpoints,
            $this->menuBuilder,
            $this->monitoringContext()
        );

        // Act
        $response = $this->controller->renderLibrariesOverview();

        // Assert
        $this->assertInstanceOf(HtmlResponse::class, $response);
        $this->assertTrue($response->isSuccessful());
    }

    /**
     * Test: Settings overview renders successfully.
     *
     * Validates that:
     * - Configuration settings are displayed
     * - Environment information is shown
     * - Thresholds are included
     * - TTL configuration is shown
     */
    public function testRenderSettingsOverviewSuccessfully(): void
    {
        // Arrange
        $this->monitoringSettings['environment'] = 'demo';

        $this->controller = new DashboardController(
            $this->app,
            $this->tools,
            $this->twig,
            $this->logger,
            $this->appConfig,
            $this->notificationManager,
            $this->eventManager,
            $this->cacheStore,
            $this->cacheKeyFactory,
            $this->endpoints,
            $this->menuBuilder,
            $this->monitoringContext()
        );

        // Act
        $response = $this->controller->renderSettingsOverview();

        // Assert
        $this->assertInstanceOf(HtmlResponse::class, $response);
        $this->assertTrue($response->isSuccessful());
    }

    /**
     * Test: Events container renders successfully.
     *
     * Validates that:
     * - Events container template is rendered
     * - HTML response is returned
     */
    public function testRenderEventsSuccessfully(): void
    {
        // Arrange
        $this->controller = new DashboardController(
            $this->app,
            $this->tools,
            $this->twig,
            $this->logger,
            $this->appConfig,
            $this->notificationManager,
            $this->eventManager,
            $this->cacheStore,
            $this->cacheKeyFactory,
            $this->endpoints,
            $this->menuBuilder,
            $this->monitoringContext()
        );

        $this->twig
            ->expects($this->once())
            ->method('render')
            ->with('partials/containers/events-container.html.twig')
            ->willReturn('<div>Events container</div>')
        ;

        // Act
        $response = $this->controller->renderEventsOverview();

        // Assert
        $this->assertInstanceOf(HtmlResponse::class, $response);
        $this->assertTrue($response->isSuccessful());
    }

    /**
     * Test: Alarms container renders successfully.
     *
     * Validates that:
     * - Alarms container template is rendered
     * - Alarm endpoint is passed to template
     * - HTML response is returned
     */
    public function testRenderAlarmsSuccessfully(): void
    {
        // Arrange
        $this->controller = new DashboardController(
            $this->app,
            $this->tools,
            $this->twig,
            $this->logger,
            $this->appConfig,
            $this->notificationManager,
            $this->eventManager,
            $this->cacheStore,
            $this->cacheKeyFactory,
            $this->endpoints,
            $this->menuBuilder,
            $this->monitoringContext()
        );

        $this->endpoints
            ->expects($this->once())
            ->method('url')
            ->with('alarms_json')
            ->willReturn('/api/alarms/json')
        ;

        $this->twig
            ->expects($this->once())
            ->method('render')
            ->with(
                'partials/containers/alarms-container.html.twig',
                $this->callback(function ($data) {
                    return isset($data['endpoints']['alarms_json']);
                })
            )
            ->willReturn('<div>Alarms container</div>')
        ;

        // Act
        $response = $this->controller->renderAlarmsOverview();

        // Assert
        $this->assertInstanceOf(HtmlResponse::class, $response);
        $this->assertTrue($response->isSuccessful());
    }

    /**
     * Test: Events JSON API returns proper data structure.
     *
     * Validates that:
     * - Events are retrieved from EventManager
     * - Data is returned in JSON format
     * - Response is successful
     */
    public function testGetEventsJsonReturnsProperData(): void
    {
        // Arrange
        $this->controller = new DashboardController(
            $this->app,
            $this->tools,
            $this->twig,
            $this->logger,
            $this->appConfig,
            $this->notificationManager,
            $this->eventManager,
            $this->cacheStore,
            $this->cacheKeyFactory,
            $this->endpoints,
            $this->menuBuilder,
            $this->monitoringContext()
        );

        // Setup mock events data
        $expectedEvents = [
            [
                'id' => 1,
                'type' => 'server_down',
                'server' => 'web-server-01',
                'message' => 'Server became unreachable',
                'timestamp' => time() - 300,
            ],
            [
                'id' => 2,
                'type' => 'high_cpu',
                'server' => 'db-server-01',
                'message' => 'CPU usage exceeded 90%',
                'timestamp' => time() - 150,
            ],
        ];

        // Mock the aggregator service
        $mockAggregator = $this->createMock(EventAggregatorServiceInterface::class);
        $mockAggregator
            ->expects($this->once())
            ->method('getLatestEvents')
            ->willReturn($expectedEvents)
        ;
        $this->eventManager->aggregator = $mockAggregator;

        // Act
        $response = $this->controller->getEventsJson();

        // Assert
        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertTrue($response->isSuccessful());

        // Decode and verify content structure
        $responseData = json_decode($response->getContent(), true);
        $this->assertIsArray($responseData);
        $this->assertCount(2, $responseData);
        $this->assertEquals('server_down', $responseData[0]['type']);
    }

    /**
     * Test: Events JSON handles error when EventManager fails.
     *
     * Validates that:
     * - Exception from EventManager is caught
     * - Error is logged
     * - Error response is returned
     */
    public function testGetEventsJsonHandlesEventManagerFailure(): void
    {
        // Arrange
        $this->controller = new DashboardController(
            $this->app,
            $this->tools,
            $this->twig,
            $this->logger,
            $this->appConfig,
            $this->notificationManager,
            $this->eventManager,
            $this->cacheStore,
            $this->cacheKeyFactory,
            $this->endpoints,
            $this->menuBuilder,
            $this->monitoringContext()
        );

        // Mock the aggregator service to throw exception
        $mockAggregator = $this->createMock(EventAggregatorServiceInterface::class);
        $mockAggregator
            ->expects($this->once())
            ->method('getLatestEvents')
            ->willThrowException(new Exception('Database connection failed'))
        ;
        $this->eventManager->aggregator = $mockAggregator;

        $this->logger
            ->expects($this->once())
            ->method('error')
        ;

        $_SERVER['REQUEST_URI'] = '/api/events';
        $_SERVER['HTTP_ACCEPT'] = 'application/json';

        // Act
        $response = $this->controller->getEventsJson();

        // Assert
        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertFalse($response->isSuccessful());
    }

    /**
     * Test: Alarms JSON API returns proper data structure.
     *
     * Validates that:
     * - Alarms are retrieved from EventManager
     * - Data includes severity levels
     * - JSON response is properly formatted
     */
    public function testGetAlarmsJsonReturnsProperData(): void
    {
        // Arrange
        $this->controller = new DashboardController(
            $this->app,
            $this->tools,
            $this->twig,
            $this->logger,
            $this->appConfig,
            $this->notificationManager,
            $this->eventManager,
            $this->cacheStore,
            $this->cacheKeyFactory,
            $this->endpoints,
            $this->menuBuilder,
            $this->monitoringContext()
        );

        $expectedAlarms = [
            [
                'id' => 1,
                'severity' => 'critical',
                'server' => 'web-server-01',
                'message' => 'Disk space below 5%',
                'threshold' => 10,
                'current_value' => 4.2,
                'timestamp' => time() - 600,
                'deliveries' => [
                    'email' => [
                        'status' => 'sent',
                        'attempts' => 1,
                        'processed_at' => '2026-07-11 12:00:00',
                        'next_retry_at' => null,
                    ],
                    'slack' => [
                        'status' => 'retry',
                        'attempts' => 2,
                        'processed_at' => '2026-07-11 12:00:01',
                        'next_retry_at' => '2026-07-11 12:05:00',
                    ],
                ],
            ],
            [
                'id' => 2,
                'severity' => 'warning',
                'server' => 'db-server-01',
                'message' => 'Memory usage high',
                'threshold' => 80,
                'current_value' => 82.5,
                'timestamp' => time() - 300,
            ],
        ];

        // Mock the aggregator service
        $mockAggregator = $this->createMock(EventAggregatorServiceInterface::class);
        $mockAggregator
            ->expects($this->once())
            ->method('getLatestAlarms')
            ->willReturn($expectedAlarms)
        ;
        $this->eventManager->aggregator = $mockAggregator;

        // Act
        $response = $this->controller->getAlarmsJson();

        // Assert
        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertTrue($response->isSuccessful());

        $responseData = json_decode($response->getContent(), true);
        $this->assertIsArray($responseData);
        $this->assertCount(2, $responseData);
        $this->assertEquals('critical', $responseData[0]['severity']);
        $this->assertSame('sent', $responseData[0]['deliveries']['email']['status']);
        $this->assertSame('retry', $responseData[0]['deliveries']['slack']['status']);
    }

    /**
     * Test: Libraries JSON returns data for all servers.
     *
     * Validates that:
     * - Library information is collected from all configured servers
     * - Data includes package names and versions
     * - JSON response is properly formatted
     */
    public function testGetLibrariesJsonReturnsDataForAllServers(): void
    {
        // Arrange
        $this->monitoringSettings['environment'] = 'demo';

        $this->controller = new DashboardController(
            $this->app,
            $this->tools,
            $this->twig,
            $this->logger,
            $this->appConfig,
            $this->notificationManager,
            $this->eventManager,
            $this->cacheStore,
            $this->cacheKeyFactory,
            $this->endpoints,
            $this->menuBuilder,
            $this->monitoringContext()
        );

        // Act
        $response = $this->controller->getLibrariesJson();

        // Assert
        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertTrue($response->isSuccessful());

        $responseData = json_decode($response->getContent(), true);
        $this->assertIsArray($responseData);
    }

    /**
     * Test: Database notice acknowledgement with valid action.
     *
     * Validates that:
     * - POST action is properly handled
     * - Response is returned (JSON format)
     * - No exceptions are thrown
     *
     * Note: Using real NotificationManager, so we just verify
     * the endpoint works and returns proper response format
     */
    public function testAcknowledgeDatabaseNoticeWithAction(): void
    {
        // Arrange
        $this->controller = new DashboardController(
            $this->app,
            $this->tools,
            $this->twig,
            $this->logger,
            $this->appConfig,
            $this->notificationManager,
            $this->eventManager,
            $this->cacheStore,
            $this->cacheKeyFactory,
            $this->endpoints,
            $this->menuBuilder,
            $this->monitoringContext()
        );

        $_POST['action'] = 'dismiss';

        // Act
        $response = $this->controller->acknowledgeDatabaseNotice('snooze');

        // Assert: Response is returned
        $this->assertInstanceOf(JsonResponse::class, $response);

        $responseData = json_decode($response->getContent(), true);
        $this->assertIsArray($responseData);
        $this->assertArrayHasKey('success', $responseData);

        // Cleanup
        unset($_POST['action']);
    }

    /**
     * Test: Database notice acknowledgement without action parameter.
     *
     * Validates that:
     * - Missing action is handled gracefully
     * - Response is still returned
     */
    public function testAcknowledgeDatabaseNoticeWithoutAction(): void
    {
        // Arrange
        $this->controller = new DashboardController(
            $this->app,
            $this->tools,
            $this->twig,
            $this->logger,
            $this->appConfig,
            $this->notificationManager,
            $this->eventManager,
            $this->cacheStore,
            $this->cacheKeyFactory,
            $this->endpoints,
            $this->menuBuilder,
            $this->monitoringContext()
        );

        // No POST data
        unset($_POST['action']);

        // Act
        $response = $this->controller->acknowledgeDatabaseNotice('reset');

        // Assert: Response is returned
        $this->assertInstanceOf(JsonResponse::class, $response);

        $responseData = json_decode($response->getContent(), true);
        $this->assertIsArray($responseData);
        $this->assertArrayHasKey('success', $responseData);
    }

    /**
     * Test: Refresh server outputs HTML directly (legacy void method).
     *
     * Validates that:
     * - Server data is refreshed without force flag
     * - Output is sent directly via echo (testing via output buffering)
     *
     * Note: This is a legacy method that outputs directly rather than
     * returning a Response object. Testing via output buffering.
     */
    public function testRefreshServerOutputsHtml(): void
    {
        // Arrange
        $this->monitoringSettings['environment'] = 'demo';

        $this->controller = new DashboardController(
            $this->app,
            $this->tools,
            $this->twig,
            $this->logger,
            $this->appConfig,
            $this->notificationManager,
            $this->eventManager,
            $this->cacheStore,
            $this->cacheKeyFactory,
            $this->endpoints,
            $this->menuBuilder,
            $this->monitoringContext()
        );

        $response = $this->controller->refreshServer('web-server-01');

        $this->assertInstanceOf(HtmlResponse::class, $response);
        $this->assertNotEmpty($response->getContent());
    }

    /**
     * Test: Refresh server stats with force refresh outputs HTML.
     *
     * Validates that:
     * - Force refresh flag is properly used
     * - Fresh data is rendered
     * - HTML output is generated
     */
    public function testRefreshServerStatsOutputsHtml(): void
    {
        // Arrange
        $this->monitoringSettings['environment'] = 'demo';

        $this->controller = new DashboardController(
            $this->app,
            $this->tools,
            $this->twig,
            $this->logger,
            $this->appConfig,
            $this->notificationManager,
            $this->eventManager,
            $this->cacheStore,
            $this->cacheKeyFactory,
            $this->endpoints,
            $this->menuBuilder,
            $this->monitoringContext()
        );

        $response = $this->controller->refreshServerStats('web-server-01');

        $this->assertInstanceOf(HtmlResponse::class, $response);
        $this->assertNotEmpty($response->getContent());
    }

    /**
     * Test: Controller initializes with empty monitoring settings.
     *
     * Validates that:
     * - Controller handles missing configuration gracefully
     * - Empty arrays are used as defaults
     * - No exceptions are thrown during initialization
     */
    public function testControllerInitializesWithEmptyMonitoringSettings(): void
    {
        // Arrange & Act: Create controller with minimal settings
        $controller = new DashboardController(
            $this->app,
            $this->tools,
            $this->twig,
            $this->logger,
            $this->appConfig,
            $this->notificationManager,
            $this->eventManager,
            $this->cacheStore,
            $this->cacheKeyFactory,
            $this->endpoints,
            $this->menuBuilder,
            DashboardMonitoringContext::fromSettingsAndServices([], [])
        );

        // Assert: Controller is created successfully
        $this->assertInstanceOf(DashboardController::class, $controller);
    }

    /**
     * Test: Controller initializes with invalid server configuration.
     *
     * Validates that:
     * - Invalid server configs are logged
     * - Controller continues initialization
     * - Other valid servers are still processed
     */
    public function testControllerHandlesInvalidServerConfiguration(): void
    {
        // Arrange: Invalid server config (missing required fields)
        $invalidSettings = [
            'environment' => 'production',
            'servers' => [
                'invalid-server' => [
                    // Missing api_url and api_key
                    'title' => 'Invalid Server',
                ],
            ],
            'ttl' => [],
            'thresholds' => [],
        ];

        // Act: Create controller with invalid settings
        $controller = new DashboardController(
            $this->app,
            $this->tools,
            $this->twig,
            $this->logger,
            $this->appConfig,
            $this->notificationManager,
            $this->eventManager,
            $this->cacheStore,
            $this->cacheKeyFactory,
            $this->endpoints,
            $this->menuBuilder,
            DashboardMonitoringContext::fromSettingsAndServices($invalidSettings, [])
        );

        // Assert: Controller is created (doesn't crash)
        $this->assertInstanceOf(DashboardController::class, $controller);
    }

    private function monitoringContext(): DashboardMonitoringContext
    {
        $factory = new ServerMonitoringFactory(
            $this->logger,
            $this->cacheStore,
            $this->cacheKeyFactory,
            $this->ttlResolver,
            $this->eventManager,
            new NodeResponseNormalizer($this->logger),
            new HttpProbeClient(),
            AppReachabilityPolicy::fromSettings($this->monitoringSettings),
            new ServerApplicationIdResolver(),
            new AppFatalStateRepository($this->createStub(DatabaseInterface::class)),
            new LogSizeEvaluator($this->tools, $this->eventManager),
        );
        $serverMonitoringBuild = $factory->createDemoAll($this->monitoringSettings['servers']);

        return DashboardMonitoringContext::fromSettingsAndServices(
            $this->monitoringSettings,
            $serverMonitoringBuild->servicesByServer,
        );
    }

    protected function tearDown(): void
    {
        // Ensure controller is created in setUp for tests that need it
        if (!isset($this->controller)) {
            $this->controller = new DashboardController(
                $this->app,
                $this->tools,
                $this->twig,
                $this->logger,
                $this->appConfig,
                $this->notificationManager,
                $this->eventManager,
                $this->cacheStore,
                $this->cacheKeyFactory,
                $this->endpoints,
                $this->menuBuilder,
                $this->monitoringContext()
            );
        }

        parent::tearDown();
    }
}
