<?php

declare(strict_types=1);

namespace Mata\Controllers;

use Exception;
use InvalidArgumentException;
use Mata\Config\Dto\ApplicationConfig;
use Mata\Core\App;
use Mata\Http\Responses\HtmlResponse;
use Mata\Http\Responses\JsonResponse;
use Mata\Http\Responses\Response;
use Mata\Http\Routing\Endpoints;
use Mata\Services\Audit\AuditAggregator;
use Mata\Services\Audit\AuditRowEnricher;
use Mata\Services\Cache\ApiCacheKeyFactory;
use Mata\Services\Cache\CacheStoreInterface;
use Mata\Services\Cache\CacheTtlResolver;
use Mata\Services\Cache\CacheTypeResolver;
use Mata\Services\Collect\DemoAlarmMetricScenario;
use Mata\Services\Collect\MockServerDataCollector;
use Mata\Services\Collect\ServerDataCollector;
use Mata\Services\Collect\ServerDataCollectorInterface;
use Mata\Services\DataTransformers\ApiResponseTransformer;
use Mata\Services\Event\EventManager;
use Mata\Services\Foundation\ApiClient;
use Mata\Services\Foundation\LogAnalyzer;
use Mata\Services\Foundation\ProbeClient;
use Mata\Services\Monitor\AppMonitor;
use Mata\Services\Monitor\Dto\ServerDashboardServices;
use Mata\Services\Monitor\MockAppMonitor;
use Mata\Services\Monitor\MockNodeHealthMonitor;
use Mata\Services\Monitor\NodeHealthMonitor;
use Mata\Services\Monitor\ServerMonitor;
use Mata\Services\Navigation\MenuBuilder;
use Mata\Services\Navigation\NotificationCenter;
use Mata\Services\Notification\NotificationManager;
use Mata\Services\View\LibrariesViewBuilder;
use Mata\Services\View\ServerViewBuilder;
use Mata\Utils\Tools;
use Mata\Utils\TypeConverter;
use Mata\Views\AlarmsRenderer;
use Mata\Views\DatabaseRenderer;
use Mata\Views\EventsRenderer;
use Mata\Views\LibrariesRenderer;
use Mata\Views\LogRenderer;
use Mata\Views\NotificationCenterRenderer;
use Mata\Views\ServerRenderer;
use Mata\Views\SettingsRenderer;
use Monolog\Logger;
use Twig\Environment;

/**
 * Main dashboard controller for server monitoring endpoints.
 *
 * Core controller handling:
 * - Dashboard rendering with server and app status
 * - Real-time data refreshing via AJAX endpoints
 * - Server monitoring with threshold evaluation
 * - App availability checking and performance metrics
 * - Event and alarm management integration
 * - Configuration loading and API initialization
 * - Template rendering for dashboard components
 */
class DashboardController extends BaseController
{
    private NotificationManager $notificationManager;
    private EventManager $eventManager;
    private Endpoints $endpoints;
    private ServerMonitor $serverMonitor;
    private LogAnalyzer $logAnalyzer;
    private CacheStoreInterface $cacheStore;
    private ApiCacheKeyFactory $cacheKeyFactory;
    private CacheTtlResolver $ttlResolver;
    private ServerViewBuilder $serverViewBuilder;
    private ServerRenderer $serverRenderer;
    private DatabaseRenderer $databaseRenderer;
    private LibrariesRenderer $librariesRenderer;
    private LibrariesViewBuilder $librariesViewBuilder;
    private LogRenderer $logRenderer;
    private SettingsRenderer $settingsRenderer;
    private EventsRenderer $eventsRenderer;
    private AlarmsRenderer $alarmsRenderer;
    private NotificationCenterRenderer $notificationCenterRenderer;
    private NotificationCenter $notificationCenter;
    private MenuBuilder $menuBuilder;
    private App $app;

    /** @var array<string,float|int> */
    private array $ttlConfig = [];

    /** @var array<string,mixed> */
    private array $thresholdsConfig = [];

    private string $monitoringEnvironment;

    /** @var array<string,ServerDashboardServices> */
    private array $dashboardServicesByServer = [];

    /** @var array<string,mixed> */
    private array $serversConfig;

    private ApplicationConfig $appConfig;
    private ?ProbeClient $probeClient = null;

    /**
     * Initialize dashboard controller with dependencies.
     *
     * Sets up server configurations, API clients, and monitoring thresholds.
     * Configures per-request HMAC authentication for server communication.
     *
     * @param App                 $app                 Application container with auth and paths
     * @param Tools               $tools               Utility functions for formatting and icons
     * @param Environment         $twig                Twig template rendering engine
     * @param Logger              $logger              PSR-3 logger for system logging
     * @param ApplicationConfig   $appConfig           Application configuration DTO
     * @param NotificationManager $notificationManager System notification management
     * @param EventManager        $eventManager        Server alarm and threshold management
     * @param CacheStoreInterface $cacheStore          Cache persistence service
     * @param ApiCacheKeyFactory  $cacheKeyFactory     API cache key factory
     * @param CacheTtlResolver    $ttlResolver         Cache TTL configuration lookup
     * @param Endpoints           $endpoints           Route endpoints for template access
     * @param MenuBuilder         $menuBuilder         Navigation menu builder service
     * @param array<string,mixed> $monitoringSettings  Complete monitoring configuration metadata
     */
    public function __construct(
        App $app,
        Tools $tools,
        Environment $twig,
        Logger $logger,
        ApplicationConfig $appConfig,
        NotificationManager $notificationManager,
        EventManager $eventManager,
        CacheStoreInterface $cacheStore,
        ApiCacheKeyFactory $cacheKeyFactory,
        CacheTtlResolver $ttlResolver,
        Endpoints $endpoints,
        MenuBuilder $menuBuilder,
        array $monitoringSettings = [],
    ) {
        $this->logger = $logger;
        $this->appConfig = $appConfig;
        $this->notificationManager = $notificationManager;
        $this->eventManager = $eventManager;
        $this->endpoints = $endpoints;
        $this->cacheStore = $cacheStore;
        $this->cacheKeyFactory = $cacheKeyFactory;
        $this->ttlResolver = $ttlResolver;
        $this->menuBuilder = $menuBuilder;
        $serversConfig = $monitoringSettings['servers'] ?? [];
        $this->serversConfig = is_array($serversConfig) ? $serversConfig : [];
        $this->ttlConfig = $monitoringSettings['ttl'] ?? [];
        $this->thresholdsConfig = $monitoringSettings['thresholds'] ?? [];
        $this->monitoringEnvironment = $monitoringSettings['environment'] ?? 'unset';

        // Initialize view renderers in dependency order
        $this->logRenderer = new LogRenderer($twig, $logger, $tools, $app, $endpoints);
        $this->serverRenderer = new ServerRenderer($twig, $logger, $tools, $app, $endpoints, $this->logRenderer);
        $this->databaseRenderer = new DatabaseRenderer($twig, $logger, $tools, $app, $endpoints);
        $this->librariesRenderer = new LibrariesRenderer($twig, $logger, $tools, $app, $endpoints);
        $this->settingsRenderer = new SettingsRenderer($twig, $logger, $tools, $app, $endpoints);
        $this->eventsRenderer = new EventsRenderer($twig, $logger, $tools, $app, $endpoints);
        $this->alarmsRenderer = new AlarmsRenderer($twig, $logger, $tools, $app, $endpoints);
        $this->notificationCenterRenderer = new NotificationCenterRenderer($twig, $logger, $tools, $app, $endpoints);
        $this->notificationCenter = new NotificationCenter(
            $eventManager,
            $cacheStore,
            $cacheKeyFactory,
        );
        $this->app = $app;

        // Initialize Libraries service classes
        $auditRowEnricher = new AuditRowEnricher(new AuditAggregator());
        $this->librariesViewBuilder = new LibrariesViewBuilder($logger, $auditRowEnricher);

        // Initialize Monitoring service classes
        $this->serverMonitor = new ServerMonitor($logger, $eventManager);
        $this->logAnalyzer = new LogAnalyzer($tools, $eventManager);
        $cacheTypeResolver = new CacheTypeResolver();
        $this->serverViewBuilder = new ServerViewBuilder(
            $this->serverMonitor,
            $this->logAnalyzer,
            $eventManager,
            $logger,
            $cacheTypeResolver,
            $tools
        );

        $demoAlarmMetricScenario = $this->appConfig->environment->env === 'demo' && $this->serversConfig !== []
            ? new DemoAlarmMetricScenario(array_keys($this->serversConfig))
            : null;

        // Initialize per-server service classes
        foreach ($this->serversConfig as $server => $serverConfig) {
            try {
                $this->dashboardServicesByServer[$server] = $this->initializeServerMonitoring(
                    $server,
                    $serverConfig,
                    $demoAlarmMetricScenario,
                );
            } catch (Exception $e) {
                $this->logger->error($server, [
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);

                $this->notificationManager->addConfigWarning(
                    'server.monitoring.failed',
                    "Couldnt initialize server monitoring/service for server: {$server}"
                );
            }
        }
    }

    /**
     * Initialize monitoring components for a server.
     *
     * @param string              $server       Server name
     * @param array<string,mixed> $serverConfig Server configuration
     */
    private function initializeServerMonitoring(
        string $server,
        array $serverConfig,
        ?DemoAlarmMetricScenario $demoAlarmMetricScenario = null,
    ): ServerDashboardServices {
        $environment = $this->appConfig->environment->env;
        $dataTransformer = new ApiResponseTransformer($this->logger);

        if ($environment === 'demo') {
            return new ServerDashboardServices(
                new MockServerDataCollector(
                    $this->logger,
                    $this->ttlResolver,
                    $dataTransformer,
                    $server,
                    $serverConfig,
                    $demoAlarmMetricScenario,
                ),
                new MockAppMonitor(),
                new MockNodeHealthMonitor(),
            );
        }

        if (!isset($serverConfig['api_key'], $serverConfig['api_url'])) {
            throw new InvalidArgumentException("Server {$server} missing required config");
        }

        // Extract security flags with defaults
        $allowHttp = TypeConverter::toBool($serverConfig['allow_http'] ?? null) ?? false;
        $suppressHttpWarning = TypeConverter::toBool($serverConfig['suppress_http_warning'] ?? null) ?? false;

        $apiClient = new ApiClient(
            $this->logger,
            $serverConfig['api_url'],
            $server,
            $serverConfig['api_key'],
            10,
            true,
            $allowHttp,
            $suppressHttpWarning
        );

        $probeClient = $this->probeClient ??= new ProbeClient();

        return new ServerDashboardServices(
            new ServerDataCollector(
                $this->logger,
                $this->cacheStore,
                $this->cacheKeyFactory,
                $this->ttlResolver,
                $apiClient,
                $dataTransformer
            ),
            new AppMonitor(
                $this->cacheStore,
                $this->cacheKeyFactory,
                $this->ttlResolver,
                $apiClient,
                $probeClient,
                $this->eventManager,
                $this->logger
            ),
            new NodeHealthMonitor(
                $this->cacheStore,
                $this->cacheKeyFactory,
                $this->ttlResolver,
                $apiClient,
                $probeClient,
                $this->eventManager,
                $this->logger
            ),
        );
    }

    /**
     * @return array<string,ServerDataCollectorInterface>
     */
    private function dataCollectorsByServer(): array
    {
        $collectors = [];
        foreach ($this->dashboardServicesByServer as $server => $dashboardServices) {
            $collectors[$server] = $dashboardServices->collector;
        }

        return $collectors;
    }

    private function requireDashboardServices(string $server): ServerDashboardServices
    {
        if (!isset($this->dashboardServicesByServer[$server])) {
            throw new InvalidArgumentException("Dashboard services unavailable for server: {$server}");
        }

        return $this->dashboardServicesByServer[$server];
    }

    /**
     * Handle acknowledgement actions for the database availability banner.
     */
    public function acknowledgeDatabaseNotice(): Response
    {
        return $this->executeWithErrorHandling(
            function () {
                $action = $_POST['action'] ?? '';

                if (!$this->notificationManager->acknowledgeOfflineNotice($action)) {
                    return new JsonResponse([
                        'success' => false,
                        'message' => 'Unsupported acknowledgement action.',
                    ], 400);
                }

                return new JsonResponse(['success' => true]);
            },
            'Failed to update database availability notice',
            'Database notice acknowledgement'
        );
    }

    // ────────────────────────────────────────────────
    // Page Rendering
    // ────────────────────────────────────────────────
    // These methods render full or partial HTML pages via Twig

    /**
     * Render main dashboard page with navigation and icons.
     */
    public function index(): Response
    {
        return $this->executeWithErrorHandling(
            function () {
                $notifications = $this->notificationManager->getActiveNotifications();
                $activeNotification = !empty($notifications) ? $notifications[0] : [];
                $menu = $this->menuBuilder->buildDashboardMenu();

                $endpoints = [
                    'server_render' => $this->endpoints->url('servers_render'),
                    'database_notice_ack' => $this->endpoints->url('database_notice_ack'),
                    'notification_bell_render' => $this->endpoints->url('notification_bell_render'),
                ];

                $layout = [
                    'server_columns' => $this->appConfig->dashboard->serverColumns,
                    'container_width' => $this->appConfig->dashboard->containerWidth,
                    'organization_name' => $this->appConfig->general->organizationName,
                    'app_env' => $this->appConfig->environment->env,
                ];

                $html = $this->serverRenderer->renderDashboardPage(
                    $activeNotification,
                    $endpoints,
                    $menu,
                    $layout
                );

                return new HtmlResponse($html);
            },
            'Failed to load dashboard',
            'Dashboard main page rendering'
        );
    }

    /**
     * Initial loading method - rendering servers container.
     */
    public function renderServersOverview(bool $forceRefresh = false): Response
    {
        return $this->executeWithErrorHandling(
            function () use ($forceRefresh) {

                if (empty($this->serversConfig)) {
                    $html = $this->serverRenderer->renderErrorContainer(
                        "No Servers configured.\nconfig/servers.json",
                        $this->serversConfig
                    );

                    return new HtmlResponse($html);
                }

                $serversViewData = $this->serverViewBuilder->buildServersViewData(
                    $this->dashboardServicesByServer,
                    $this->serversConfig,
                    $forceRefresh
                );

                $layoutConfig = [
                    'server_columns' => $this->appConfig->dashboard->serverColumns,
                    'container_width' => $this->appConfig->dashboard->containerWidth,
                ];

                $html = $this->serverRenderer->renderServersContainer(
                    $serversViewData,
                    $layoutConfig
                );

                return new HtmlResponse($html);
            },
            'Failed to load servers',
            'Servers container rendering'
        );
    }

    /**
     * Refresh databases container via AJAX.
     */
    public function renderDatabasesOverview(bool $forceRefresh = false): Response
    {
        return $this->executeWithErrorHandling(
            function () use ($forceRefresh) {
                $html = $this->databaseRenderer->renderContainer(
                    $this->dataCollectorsByServer(),
                    $this->serversConfig,
                    $forceRefresh
                );

                return new HtmlResponse($html);
            },
            'Failed to load databases overview',
            'Databases container rendering'
        );
    }

    /**
     * Show libraries container template.
     */
    public function renderLibrariesOverview(): Response
    {
        return $this->executeWithErrorHandling(
            function () {
                $html = $this->librariesRenderer->renderContainer(
                    $this->endpoints,
                    $this->serversConfig
                );

                return new HtmlResponse($html);
            },
            'Failed to load libraries overview',
            'Libraries container rendering'
        );
    }

    /**
     * Show configuration settings overview.
     */
    public function renderSettingsOverview(): Response
    {
        return $this->executeWithErrorHandling(
            function () {
                $environment = $this->monitoringEnvironment;
                $appEnv = $this->appConfig->environment->env;

                $html = $this->settingsRenderer->renderContainer(
                    $environment,
                    $appEnv,
                    $this->serversConfig,
                    $this->ttlConfig,
                    $this->thresholdsConfig
                );

                return new HtmlResponse($html);
            },
            'Failed to load settings overview',
            'Settings container rendering'
        );
    }

    /**
     * Show events monitoring container.
     */
    public function renderEventsOverview(): Response
    {
        return $this->executeWithErrorHandling(
            function () {
                $html = $this->eventsRenderer->renderContainer(
                    $this->endpoints
                );

                return new HtmlResponse($html);
            },
            'Failed to load events',
            'Events container rendering'
        );
    }

    /**
     * Show alarms monitoring container.
     */
    public function renderAlarmsOverview(): Response
    {
        return $this->executeWithErrorHandling(
            function () {
                $html = $this->alarmsRenderer->renderContainer(
                    $this->endpoints
                );

                return new HtmlResponse($html);
            },
            'Failed to load alarms',
            'Alarms container rendering'
        );
    }

    // ────────────────────────────────────────────────
    // Data Refresh
    // ────────────────────────────────────────────────
    // Lightweight endpoints used for live refreshes

    /**
     * Refresh individual server card via AJAX.
     *
     * @param string $server       Server identifier
     * @param bool   $forceRefresh Force cache bypass
     */
    public function refreshServer(string $server, bool $forceRefresh = false): Response
    {
        return $this->executeWithErrorHandling(
            function () use ($server, $forceRefresh) {

                if (!isset($this->serversConfig[$server])) {
                    throw new InvalidArgumentException("Unknown server: {$server}");
                }

                $dashboardServices = $this->requireDashboardServices($server);
                $serversViewData = $this->serverViewBuilder->buildServerViewData(
                    $server,
                    $this->serversConfig[$server],
                    $dashboardServices->collector,
                    $dashboardServices->appMonitor,
                    $forceRefresh,
                    $dashboardServices->healthMonitor
                );

                $html = $this->serverRenderer->renderServerCard(
                    $serversViewData
                );

                return new HtmlResponse($html);
            },
            'Failed to load server',
            'Server card rendering'
        );
    }

    /**
     * Refresh individual serverstats via AJAX.
     *
     * @param string $server       Server identifier
     * @param bool   $forceRefresh Force cache bypass
     */
    public function refreshServerStats(string $server, bool $forceRefresh = false): Response
    {
        return $this->executeWithErrorHandling(
            function () use ($server, $forceRefresh) {

                if (!isset($this->serversConfig[$server])) {
                    throw new InvalidArgumentException("Unknown server: {$server}");
                }

                $dashboardServices = $this->requireDashboardServices($server);
                $serverStatsData = $this->serverViewBuilder->buildServerStatsData(
                    $server,
                    $this->serversConfig[$server],
                    $dashboardServices->collector,
                    $forceRefresh
                );

                $html = $this->serverRenderer->renderServerStats(
                    $serverStatsData
                );

                return new HtmlResponse($html);
            },
            'Failed to load server stats',
            'Server stats fetching'
        );
    }

    // ────────────────────────────────────────────────
    // JSON API Endpoints
    // ────────────────────────────────────────────────
    // Return data in JSON format for client-side requests

    /**
     * Get monitoring events as JSON for AJAX requests.
     *
     * @return Response JSON response with events data
     */
    public function getEventsJson(): Response
    {
        return $this->executeWithErrorHandling(
            function () {
                $events = $this->eventManager->aggregator->getLatestEvents();

                return new JsonResponse($events);
            },
            'Failed to retrieve events',
            'Events JSON API request'
        );
    }

    /**
     * Get monitoring alarms as JSON for AJAX requests.
     *
     * @return Response JSON response with alarms data
     */
    public function getAlarmsJson(): Response
    {
        return $this->executeWithErrorHandling(
            function () {
                $unacknowledgedOnly = ($_GET['unacknowledged'] ?? '') === '1';
                $alarms = $this->eventManager->aggregator->getLatestAlarms(
                    unacknowledgedOnly: $unacknowledgedOnly
                );

                return new JsonResponse($alarms);
            },
            'Failed to retrieve alarms',
            'Alarms JSON API request'
        );
    }

    /**
     * Render the topbar notification bell fragment (polled via htmx).
     */
    public function renderNotificationBell(): Response
    {
        return $this->executeWithErrorHandling(
            function () {
                $html = $this->notificationCenterRenderer->renderBell(
                    $this->notificationCenter->getUnacknowledgedCounts()
                );

                return new HtmlResponse($html);
            },
            'Failed to render notification bell',
            'Notification bell fragment rendering'
        );
    }

    /**
     * Acknowledge a single alarm and return the acknowledgement result.
     *
     * @param string $alarmId Alarm identifier from the route
     */
    public function acknowledgeAlarm(string $alarmId): Response
    {
        return $this->executeWithErrorHandling(
            function () use ($alarmId) {
                $user = $this->app->auth->getUsername() ?? 'unknown';
                $acknowledged = $this->notificationCenter->acknowledge((int) $alarmId, $user);

                return new JsonResponse([
                    'success' => true,
                    'acknowledged' => $acknowledged,
                ]);
            },
            'Failed to acknowledge alarm',
            'Alarm acknowledgement'
        );
    }

    /**
     * Acknowledge all unacknowledged alarms and return the refreshed bell fragment.
     */
    public function acknowledgeAllAlarms(): Response
    {
        return $this->executeWithErrorHandling(
            function () {
                $user = $this->app->auth->getUsername() ?? 'unknown';
                $this->notificationCenter->acknowledgeAll($user);

                $html = $this->notificationCenterRenderer->renderBell(
                    $this->notificationCenter->getUnacknowledgedCounts()
                );

                return new HtmlResponse($html);
            },
            'Failed to acknowledge alarms',
            'Alarm bulk acknowledgement'
        );
    }

    /**
     * Get libraries data as JSON for all servers and apps.
     *
     * @return Response JSON response with libraries data
     */
    public function getLibrariesJson(): Response
    {
        return $this->executeWithErrorHandling(
            function () {
                $libraryRows = $this->librariesViewBuilder->buildLibrariesViewData($this->dataCollectorsByServer(), $this->serversConfig);

                return new JsonResponse($libraryRows);
            },
            'Failed to retrieve libraries data',
            'Libraries JSON API request'
        );
    }
}
