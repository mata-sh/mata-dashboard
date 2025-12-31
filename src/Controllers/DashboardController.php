<?php

declare(strict_types=1);

namespace Watcher\Controllers;

use Exception;
use InvalidArgumentException;
use Monolog\Logger;
use Twig\Environment;
use Watcher\Config\Dto\ApplicationConfig;
use Watcher\Core\App;
use Watcher\Http\Responses\HtmlResponse;
use Watcher\Http\Responses\JsonResponse;
use Watcher\Http\Responses\Response;
use Watcher\Http\Routing\Endpoints;
use Watcher\Services\Cache\CacheTypeResolver;
use Watcher\Services\Collect\MockServerDataCollector;
use Watcher\Services\Collect\ServerDataCollector;
use Watcher\Services\Collect\ServerDataCollectorInterface;
use Watcher\Services\Foundation\ApiClient;
use Watcher\Services\Foundation\CacheManager;
use Watcher\Services\Foundation\DataTransformers\ApiResponseTransformer;
use Watcher\Services\Foundation\EventManager;
use Watcher\Services\Foundation\LogAnalyzer;
use Watcher\Services\Foundation\NotificationManager;
use Watcher\Services\Monitor\AppMonitor;
use Watcher\Services\Monitor\AppMonitorInterface;
use Watcher\Services\Monitor\MockAppMonitor;
use Watcher\Services\Monitor\ServerMonitor;
use Watcher\Services\Navigation\MenuBuilder;
use Watcher\Services\View\ServerViewBuilder;
use Watcher\Utils\Tools;
use Watcher\Views\AlarmsRenderer;
use Watcher\Views\CronjobRenderer;
use Watcher\Views\DatabaseRenderer;
use Watcher\Views\EventsRenderer;
use Watcher\Views\LibrariesRenderer;
use Watcher\Views\LogRenderer;
use Watcher\Views\ServerRenderer;
use Watcher\Views\SettingsRenderer;

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
    private CacheManager $cacheManager;
    private ServerViewBuilder $serverViewBuilder;
    private ServerRenderer $serverRenderer;
    private DatabaseRenderer $databaseRenderer;
    private LibrariesRenderer $librariesRenderer;
    private CronjobRenderer $cronjobRenderer;
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
     * @param CacheManager        $cacheManager        Cache management service with TTL configuration
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
        CacheManager $cacheManager,
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
        $this->cacheManager = $cacheManager;
        $this->menuBuilder = $menuBuilder;
        $this->serversConfig = is_array($monitoringSettings['servers']) ? $monitoringSettings['servers'] : [];
        $this->ttlConfig = $monitoringSettings['ttl'] ?? [];
        $this->thresholdsConfig = $monitoringSettings['thresholds'] ?? [];
        $this->monitoringEnvironment = $monitoringSettings['environment'] ?? 'unset';

        // Initialize view renderers in dependency order
        $this->logRenderer = new LogRenderer($twig, $logger, $tools, $app);
        $this->serverRenderer = new ServerRenderer($twig, $logger, $tools, $app, $this->logRenderer);
        $this->databaseRenderer = new DatabaseRenderer($twig, $logger, $tools, $app);
        $this->librariesRenderer = new LibrariesRenderer($twig, $logger, $tools, $app);
        $this->cronjobRenderer = new CronjobRenderer($twig, $logger, $tools, $app);
        $this->settingsRenderer = new SettingsRenderer($twig, $logger, $tools, $app);
        $this->eventsRenderer = new EventsRenderer($twig, $logger, $tools, $app);
        $this->alarmsRenderer = new AlarmsRenderer($twig, $logger, $tools, $app);

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
     * @return array{collector: ServerDataCollectorInterface, appMonitor: AppMonitorInterface}
     */
    private function initializeServerMonitoring(string $server, array $serverConfig): array
    {
        $environment = $this->appConfig->environment->env;
        $dataTransformer = new ApiResponseTransformer($this->logger);

        if ($environment === 'demo') {
            return [
                'collector' => new MockServerDataCollector(
                    $this->logger,
                    $this->cacheManager,
                    $dataTransformer,
                    $server,
                    $serverConfig
                ),
                'appMonitor' => new MockAppMonitor(),
            ];
        }

        if (!isset($serverConfig['api_key'], $serverConfig['api_url'])) {
            throw new InvalidArgumentException("Server {$server} missing required config");
        }

        $apiKey = $serverConfig['api_key'] . '|' . time();
        $secret = $this->appConfig->secrets->watcherEncryptionSecret;
        $token = $this->tools->encrypt($apiKey, $secret);

        $apiClient = new ApiClient(
            $this->logger,
            $serverConfig['api_url'],
            $token
        );

        return [
            'collector' => new ServerDataCollector(
                $this->logger,
                $this->cacheManager,
                $apiClient,
                $dataTransformer
            ),
            'appMonitor' => new AppMonitor(
                $this->cacheManager,
                $apiClient,
                $this->eventManager,
                $this->logger
            ),
        ];
    }

    /**
     * Handle acknowledgement actions for the database availability banner.
     */
    public function acknowledgeDatabaseNotice(string $action): Response
    {
        return $this->executeWithErrorHandling(
            function () use ($action) {
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
                    'server_render' => $this->endpoints->get('servers_render'),
                    'database_notice_ack' => $this->endpoints->get('database_notice_ack'),
                ];

                $layout = [
                    'server_columns' => $this->appConfig->dashboard->serverColumns,
                    'container_width' => $this->appConfig->dashboard->containerWidth,
                    'organization_name' => $this->appConfig->general->organizationName,
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
                    $this->endpoints
                );

                return new HtmlResponse($html);
            },
            'Failed to load libraries overview',
            'Libraries container rendering'
        );
    }

    /**
     * Show cronjobs monitoring container.
     */
    public function renderCronjobsOverview(): Response
    {
        return $this->executeWithErrorHandling(
            function () {
                $html = $this->cronjobRenderer->renderContainer(
                    $this->serversDataCollector,
                    $this->serversConfig
                );

                return new HtmlResponse($html);
            },
            'Failed to load cronjobs overview',
            'Cronjobs container rendering'
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
                    $forceRefresh
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
                $events = $this->eventManager->getLatestEvents();

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
                $alarms = $this->eventManager->getLatestAlarms();

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
