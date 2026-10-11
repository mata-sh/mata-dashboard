<?php

declare(strict_types=1);

namespace Mata\Controllers;

use InvalidArgumentException;
use Mata\Config\Dto\ApplicationConfig;
use Mata\Config\Monitoring\MonitoringConfig;
use Mata\Core\App;
use Mata\Http\Request;
use Mata\Http\Responses\HtmlResponse;
use Mata\Http\Responses\JsonResponse;
use Mata\Http\Responses\Response;
use Mata\Http\Routing\Endpoints;
use Mata\Services\Audit\AuditAggregator;
use Mata\Services\Audit\AuditRowEnricher;
use Mata\Services\Cache\ApiCacheKeyFactory;
use Mata\Services\Cache\CacheStoreInterface;
use Mata\Services\Cache\CacheTypeResolver;
use Mata\Services\Collect\Demo\DemoCronCatalog;
use Mata\Services\Collect\ServerDataCollectorInterface;
use Mata\Services\Cron\CronHeartbeatReader;
use Mata\Services\Cron\Dto\CronJobStatus;
use Mata\Services\Event\EventManager;
use Mata\Services\Monitor\Dto\ServerMonitoringResult;
use Mata\Services\Monitor\Dto\ServerMonitoringServices;
use Mata\Services\Monitor\LogSizeEvaluator;
use Mata\Services\Monitor\ServerMonitor;
use Mata\Services\Navigation\AlarmNotificationCenter;
use Mata\Services\Navigation\MenuBuilder;
use Mata\Services\Notification\SystemNotificationManager;
use Mata\Services\Sync\AppInventorySync;
use Mata\Services\View\LibrariesViewBuilder;
use Mata\Services\View\ServerViewBuilder;
use Mata\Utils\Tools;
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
    private SystemNotificationManager $notificationManager;
    private EventManager $eventManager;
    private Endpoints $endpoints;
    private ServerMonitor $serverMonitor;
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
    private AlarmNotificationCenter $notificationCenter;
    private MenuBuilder $menuBuilder;
    private App $app;

    private MonitoringConfig $monitoringConfig;

    /** @var array<string,ServerMonitoringServices> */
    private array $dashboardServicesByServer = [];

    private ApplicationConfig $appConfig;

    /**
     * Initialize dashboard controller with dependencies.
     *
     * Uses prebuilt per-server monitoring services and configuration.
     *
     * @param App                       $app                 Application container with auth and paths
     * @param Tools                     $tools               Utility functions for formatting and icons
     * @param Environment               $twig                Twig template rendering engine
     * @param Logger                    $logger              PSR-3 logger for system logging
     * @param ApplicationConfig         $appConfig           Application configuration DTO
     * @param SystemNotificationManager $notificationManager System notification management
     * @param EventManager              $eventManager        Server alarm and threshold management
     * @param CacheStoreInterface       $cacheStore          Cache persistence service
     * @param ApiCacheKeyFactory        $cacheKeyFactory     API cache key factory
     * @param Endpoints                 $endpoints           Route endpoints for template access
     * @param MenuBuilder               $menuBuilder         Navigation menu builder service
     * @param MonitoringConfig          $monitoringConfig    Dashboard monitoring configuration
     * @param ServerMonitoringResult    $monitoringResult    Prebuilt per-server monitoring services
     * @param null|AppInventorySync     $appInventorySync    Syncs apps during manual force-refresh recovery
     */
    public function __construct(
        App $app,
        Tools $tools,
        Environment $twig,
        Logger $logger,
        ApplicationConfig $appConfig,
        SystemNotificationManager $notificationManager,
        EventManager $eventManager,
        CacheStoreInterface $cacheStore,
        ApiCacheKeyFactory $cacheKeyFactory,
        Endpoints $endpoints,
        MenuBuilder $menuBuilder,
        MonitoringConfig $monitoringConfig,
        ServerMonitoringResult $monitoringResult,
        Request $request,
        ?AppInventorySync $appInventorySync = null,
    ) {
        parent::__construct($request);
        $this->logger = $logger;
        $this->appConfig = $appConfig;
        $this->notificationManager = $notificationManager;
        $this->eventManager = $eventManager;
        $this->endpoints = $endpoints;
        $this->menuBuilder = $menuBuilder;
        $this->monitoringConfig = $monitoringConfig;
        $this->dashboardServicesByServer = $monitoringResult->servicesByServer;

        // Initialize view renderers in dependency order
        $this->logRenderer = new LogRenderer($twig, $logger, $tools, $app, $endpoints);
        $this->serverRenderer = new ServerRenderer($twig, $logger, $tools, $app, $endpoints, $this->logRenderer);
        $this->databaseRenderer = new DatabaseRenderer($twig, $logger, $tools, $app, $endpoints);
        $this->librariesRenderer = new LibrariesRenderer($twig, $logger, $tools, $app, $endpoints);
        $this->settingsRenderer = new SettingsRenderer($twig, $logger, $tools, $app, $endpoints);
        $this->eventsRenderer = new EventsRenderer($twig, $logger, $tools, $app, $endpoints);
        $this->alarmsRenderer = new AlarmsRenderer($twig, $logger, $tools, $app, $endpoints);
        $this->notificationCenterRenderer = new NotificationCenterRenderer($twig, $logger, $tools, $app, $endpoints);
        $this->notificationCenter = new AlarmNotificationCenter(
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
        $cacheTypeResolver = new CacheTypeResolver();
        $this->serverViewBuilder = new ServerViewBuilder(
            $this->serverMonitor,
            new LogSizeEvaluator($tools, $eventManager),
            $eventManager,
            $logger,
            $cacheTypeResolver,
            $appInventorySync
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

    private function requireDashboardServices(string $server): ServerMonitoringServices
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
                $action = $this->request->postString('action') ?? '';

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
     * Dashboard cron jobs for the summary bar, read once per servers overview.
     * Demo mode shows every job on schedule, since its cron may not run at all.
     *
     * @return list<CronJobStatus>
     */
    private function readCronStatuses(): array
    {
        $paths = $this->app->paths;
        $reader = new CronHeartbeatReader($paths->appPath . '/cron', $paths->logsPath . '/cron');

        if ($this->appConfig->environment->env === 'demo') {
            return (new DemoCronCatalog())->statuses($reader->jobs(), time());
        }

        return $reader->read(time());
    }

    /**
     * Initial loading method - rendering servers container.
     */
    public function renderServersOverview(bool $forceRefresh = false): Response
    {
        return $this->executeWithErrorHandling(
            function () use ($forceRefresh) {

                if (empty($this->monitoringConfig->servers->values)) {
                    $html = $this->serverRenderer->renderErrorContainer(
                        "No Servers configured.\nconfig/servers.json",
                        $this->monitoringConfig->servers->values
                    );

                    return new HtmlResponse($html);
                }

                $serversViewData = $this->serverViewBuilder->buildServersViewData(
                    $this->dashboardServicesByServer,
                    $this->monitoringConfig->servers->values,
                    $forceRefresh
                );

                $layoutConfig = [
                    'server_columns' => $this->appConfig->dashboard->serverColumns,
                    'container_width' => $this->appConfig->dashboard->containerWidth,
                ];

                $html = $this->serverRenderer->renderServersContainer(
                    $serversViewData,
                    $layoutConfig,
                    $this->readCronStatuses()
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
                    $this->monitoringConfig->servers->values,
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
                    $this->monitoringConfig->servers->values
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
                $html = $this->settingsRenderer->renderContainer(
                    $this->appConfig->environment->env,
                    $this->monitoringConfig->servers,
                    $this->monitoringConfig->ttl,
                    $this->monitoringConfig->thresholds,
                    $this->monitoringConfig->intervals,
                    $this->monitoringConfig->probes,
                    $this->monitoringConfig->alarmRateLimits,
                    $this->appConfig->mail
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
        if (!isset($this->monitoringConfig->servers->values[$server])) {
            return $this->handleHtmlNotFound("Server '{$server}'", 'Server card rendering');
        }

        return $this->executeWithErrorHandling(
            function () use ($server, $forceRefresh) {
                $dashboardServices = $this->requireDashboardServices($server);
                $serversViewData = $this->serverViewBuilder->buildServerViewData(
                    $server,
                    $this->monitoringConfig->servers->values[$server],
                    $dashboardServices->collector,
                    $dashboardServices->reachabilityMonitor,
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
     * Refresh one server's database card via AJAX.
     *
     * @param string $server       Server identifier
     * @param bool   $forceRefresh Force cache bypass
     */
    public function refreshDatabase(string $server, bool $forceRefresh = false): Response
    {
        if (!isset($this->monitoringConfig->servers->values[$server])) {
            return $this->handleHtmlNotFound("Server '{$server}'", 'Database card rendering');
        }

        return $this->executeWithErrorHandling(
            function () use ($server, $forceRefresh) {
                $html = $this->databaseRenderer->renderDatabaseCards(
                    [$server => $this->requireDashboardServices($server)->collector],
                    [$server => $this->monitoringConfig->servers->values[$server]],
                    $forceRefresh
                );

                return new HtmlResponse($html);
            },
            'Failed to load database card',
            'Database card rendering'
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
        if (!isset($this->monitoringConfig->servers->values[$server])) {
            return $this->handleHtmlNotFound("Server '{$server}'", 'Server stats fetching');
        }

        return $this->executeWithErrorHandling(
            function () use ($server, $forceRefresh) {
                $dashboardServices = $this->requireDashboardServices($server);
                $serverStatsData = $this->serverViewBuilder->buildServerStatsData(
                    $server,
                    $this->monitoringConfig->servers->values[$server],
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
                $unacknowledgedOnly = $this->request->queryString('unacknowledged') === '1';
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
                $libraryRows = $this->librariesViewBuilder->buildLibrariesViewData($this->dataCollectorsByServer(), $this->monitoringConfig->servers->values);

                return new JsonResponse($libraryRows);
            },
            'Failed to retrieve libraries data',
            'Libraries JSON API request'
        );
    }
}
