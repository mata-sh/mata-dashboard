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
use Mata\Services\Cache\ApiCacheKeyFactory;
use Mata\Services\Cache\CacheStoreInterface;
use Mata\Services\Cache\CacheTtlResolver;
use Mata\Services\Cache\CacheTypeResolver;
use Mata\Services\Collect\MockServerDataCollector;
use Mata\Services\Collect\ServerDataCollector;
use Mata\Services\Collect\ServerDataCollectorInterface;
use Mata\Services\DataTransformers\ApiResponseTransformer;
use Mata\Services\Event\EventManager;
use Mata\Services\Foundation\ApiClient;
use Mata\Services\Foundation\LogAnalyzer;
use Mata\Services\Monitor\AppMonitor;
use Mata\Services\Monitor\AppMonitorInterface;
use Mata\Services\Monitor\MockAppMonitor;
use Mata\Services\Monitor\MockNodeHealthMonitor;
use Mata\Services\Monitor\NodeHealthMonitor;
use Mata\Services\Monitor\NodeHealthMonitorInterface;
use Mata\Services\Monitor\ServerMonitor;
use Mata\Services\Navigation\MenuBuilder;
use Mata\Services\Notification\NotificationManager;
use Mata\Services\View\ServerViewBuilder;
use Mata\Utils\Tools;
use Mata\Utils\TypeConverter;
use Mata\Views\AlarmsRenderer;
use Mata\Views\DatabaseRenderer;
use Mata\Views\EventsRenderer;
use Mata\Views\LibrariesRenderer;
use Mata\Views\LogRenderer;
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
    private Tools $tools;
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
    private LogRenderer $logRenderer;
    private SettingsRenderer $settingsRenderer;
    private EventsRenderer $eventsRenderer;
    private AlarmsRenderer $alarmsRenderer;
    private MenuBuilder $menuBuilder;

    /** @var array<string,float|int> */
    private array $ttlConfig = [];

    /** @var array<string,mixed> */
    private array $thresholdsConfig = [];

    private string $monitoringEnvironment;

    /** @var array<string,ServerDataCollectorInterface> */
    private array $serversDataCollector = [];

    /** @var array<string,AppMonitorInterface> */
    private array $appMonitors = [];

    /** @var array<string,NodeHealthMonitorInterface> */
    private array $nodeHealthMonitors = [];

    /** @var array<string,mixed> */
    private array $serversConfig;

    private ApplicationConfig $appConfig;

    /**
     * Initialize dashboard controller with dependencies.
     *
     * Sets up server configurations, API clients, and monitoring thresholds.
     * Creates encrypted API tokens for server communication.
     *
     * @param App                 $app                 Application container with auth and paths
     * @param Tools               $tools               Utility functions for formatting and icons
     * @param Environment         $twig                Twig template rendering engine
     * @param Logger              $logger              PSR-3 logger for system logging
     * @param ApplicationConfig   $appConfig           Application configuration DTO
     * @param NotificationManager $notificationManager System notification management
     * @param EventManager        $eventManager        Server alert and threshold management
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
        $this->tools = $tools;
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

        // Initialize service classes
        $this->serverMonitor = new ServerMonitor($logger, $eventManager);
        $this->logAnalyzer = new LogAnalyzer($tools, $eventManager);
        $cacheTypeResolver = new CacheTypeResolver();
        $this->serverViewBuilder = new ServerViewBuilder(
            $this->serverMonitor,
            $this->logAnalyzer,
            $eventManager,
            $logger,
            $cacheTypeResolver
        );

        // Initialize per-server service classes
        foreach ($this->serversConfig as $server => $serverConfig) {
            try {
                $services = $this->initializeServerMonitoring($server, $serverConfig);
                $this->serversDataCollector[$server] = $services['collector'];
                $this->appMonitors[$server] = $services['appMonitor'];
                $this->nodeHealthMonitors[$server] = $services['healthMonitor'];

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
     *
     * @return array{collector: ServerDataCollectorInterface, appMonitor: AppMonitorInterface, healthMonitor: NodeHealthMonitorInterface}
     */
    private function initializeServerMonitoring(string $server, array $serverConfig): array
    {
        $environment = $this->appConfig->environment->env;
        $dataTransformer = new ApiResponseTransformer($this->logger);

        if ($environment === 'demo') {
            return [
                'collector' => new MockServerDataCollector(
                    $this->logger,
                    $this->ttlResolver,
                    $dataTransformer,
                    $server,
                    $serverConfig
                ),
                'appMonitor' => new MockAppMonitor(),
                'healthMonitor' => new MockNodeHealthMonitor(),
            ];
        }

        if (!isset($serverConfig['api_key'], $serverConfig['api_url'])) {
            throw new InvalidArgumentException("Server {$server} missing required config");
        }

        $timestamp = time();
        $token = $this->tools->buildHmacAuthToken($server, $serverConfig['api_key'], $timestamp);

        // Extract security flags with defaults
        $allowHttp = TypeConverter::toBool($serverConfig['allow_http'] ?? null) ?? false;
        $suppressHttpWarning = TypeConverter::toBool($serverConfig['suppress_http_warning'] ?? null) ?? false;

        $apiClient = new ApiClient(
            $this->logger,
            $serverConfig['api_url'],
            $token,
            10,
            true,
            $allowHttp,
            $suppressHttpWarning
        );

        return [
            'collector' => new ServerDataCollector(
                $this->logger,
                $this->cacheStore,
                $this->cacheKeyFactory,
                $this->ttlResolver,
                $apiClient,
                $dataTransformer
            ),
            'appMonitor' => new AppMonitor(
                $this->cacheStore,
                $this->cacheKeyFactory,
                $this->ttlResolver,
                $apiClient,
                $this->eventManager,
                $this->logger
            ),
            'healthMonitor' => new NodeHealthMonitor(
                $this->cacheStore,
                $this->cacheKeyFactory,
                $this->ttlResolver,
                $apiClient,
                $this->eventManager,
                $this->logger
            ),
        ];
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
                        "No Servers configured.<br> config/{$this->appConfig->environment->env}/servers.json",
                        $this->serversConfig
                    );

                    return new HtmlResponse($html);
                }

                $serversViewData = $this->serverViewBuilder->buildServersViewData(
                    $this->serversDataCollector,
                    $this->appMonitors,
                    $this->nodeHealthMonitors,
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
                    $this->serversDataCollector,
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

                $serversViewData = $this->serverViewBuilder->buildServerViewData(
                    $server,
                    $this->serversConfig[$server],
                    $this->serversDataCollector[$server],
                    $this->appMonitors[$server],
                    $forceRefresh,
                    $this->nodeHealthMonitors[$server] ?? null
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

                $serverStatsData = $this->serverViewBuilder->buildServerStatsData(
                    $server,
                    $this->serversConfig[$server],
                    $this->serversDataCollector[$server],
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
                $alarms = $this->eventManager->aggregator->getLatestAlarms();

                return new JsonResponse($alarms);
            },
            'Failed to retrieve alarms',
            'Alarms JSON API request'
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
                $libraryRows = $this->librariesRenderer->prepareJsonData(
                    $this->serversDataCollector,
                    $this->serversConfig
                );

                return new JsonResponse($libraryRows);
            },
            'Failed to retrieve libraries data',
            'Libraries JSON API request'
        );
    }
}
