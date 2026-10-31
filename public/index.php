<?php

declare(strict_types=1);

use Bramus\Router\Router;
use Mata\Bootstrap\AdvisoryRuntime;
use Mata\Bootstrap\DashboardRuntime;
use Mata\Controllers\AdvisoryController;
use Mata\Controllers\AuditController;
use Mata\Controllers\DashboardController;
use Mata\Controllers\LoginController;
use Mata\Controllers\MetricsController;
use Mata\Controllers\ModalController;
use Mata\Http\Routing\Endpoints;
use Mata\Http\Routing\RouterSetup;
use Mata\Services\Audit\AuditAggregator;
use Mata\Services\Audit\AuditRowEnricher;
use Mata\Services\Cache\ApiCacheKeyFactory;
use Mata\Services\Cache\CacheTtlResolver;
use Mata\Services\Cache\CacheTypeResolver;
use Mata\Services\Cache\FileCacheStore;
use Mata\Services\Cache\MetricsCacheService;
use Mata\Services\Cache\ModalCacheService;
use Mata\Services\Collect\NodeResponseNormalizer;
use Mata\Services\DataTransformers\ChartDataTransformer;
use Mata\Services\DataTransformers\LogDataTransformer;
use Mata\Services\Monitor\Dto\AppReachabilityPolicy;
use Mata\Services\Monitor\HttpProbeClient;
use Mata\Services\Monitor\LogSizeEvaluator;
use Mata\Services\Monitor\MetricThresholds;
use Mata\Services\Monitor\ServerMonitor;
use Mata\Services\Monitor\ServerMonitoringFactory;
use Mata\Services\Navigation\AlarmNotificationCenter;
use Mata\Services\Navigation\MenuBuilder;
use Mata\Services\ServerMetrics\MetricCatalog;
use Mata\Services\ServerMetrics\MetricQueryPolicy;
use Mata\Services\ServerMetrics\MetricsRequestValidator;
use Mata\Services\ServerMetrics\ServerMetricsQueryFactory;
use Mata\Services\View\AdvisoriesViewBuilder;
use Mata\Services\View\ChartViewBuilder;
use Mata\Services\View\LibrariesViewBuilder;
use Mata\Services\View\MetricsViewBuilder;
use Mata\Services\View\ServerViewBuilder;
use Mata\Views\AdvisoriesRenderer;
use Mata\Views\AuditRenderer;
use Mata\Views\DashboardRenderers;
use Mata\Views\MetricsRenderer;
use Mata\Views\ModalRenderer;

require_once '../vendor/autoload.php';

// ************************************************************************************************
// Runtime: configuration, logging, session, authentication, database
// ************************************************************************************************
$runtime = DashboardRuntime::boot(__FILE__);

$paths = $runtime->paths;
$log = $runtime->log;
$request = $runtime->request;
$twig = $runtime->twig;
$tools = $runtime->tools;
$appConfig = $runtime->appConfig;
$app = $runtime->app;
$csrf = $runtime->csrf;
$db = $runtime->db;
$notificationManager = $runtime->notifications;

// ************************************************************************************************
// Monitoring configuration, events and alarms
// ************************************************************************************************
$metricCatalog = new MetricCatalog();
$metricQueryPolicy = new MetricQueryPolicy();
$monitoring = $runtime->monitoring($metricCatalog, $metricQueryPolicy);

$eventManager = $monitoring->eventManager;
$idResolver = $monitoring->idResolver;
$monitoringConfig = $monitoring->monitoringConfig;
$serversConfig = $monitoringConfig->servers->values;

// ************************************************************************************************
// Cache & Endpoints
// ************************************************************************************************
$cacheStore = new FileCacheStore($log, $paths->apiCachePath);
$cacheKeyFactory = new ApiCacheKeyFactory();
$ttlResolver = new CacheTtlResolver($monitoringConfig->ttl);
$endpoints = new Endpoints();
// Advisories: the menu entry follows advisories.json unless menu.json says otherwise.
$advisoryRuntime = AdvisoryRuntime::build($paths, $appConfig, $db, $eventManager);
$menuBuilder = new MenuBuilder($endpoints, $tools, $monitoringConfig->menu->overrides + ['advisories' => $advisoryRuntime->config->configured]);

// ************************************************************************************************
// Server Monitoring Services (live API or demo mocks)
// ************************************************************************************************
$thresholds = MetricThresholds::fromConfig($monitoringConfig->thresholds);
$logSizeEvaluator = new LogSizeEvaluator($tools, $thresholds);
$serverMonitoringFactory = new ServerMonitoringFactory(
    $log,
    $cacheStore,
    $cacheKeyFactory,
    $ttlResolver,
    $eventManager,
    new NodeResponseNormalizer($log),
    new HttpProbeClient(),
    AppReachabilityPolicy::fromMonitoringConfig($monitoringConfig),
    $idResolver,
    $monitoring->fatalStateRepository,
    $logSizeEvaluator,
    $appConfig->demo,
);

$serverMonitoringResult = $monitoring->environmentFactory->createMonitoring($serversConfig, $serverMonitoringFactory);

foreach ($serverMonitoringResult->failures as $server => $reason) {
    $notificationManager->addConfigWarning(
        'server.monitoring.failed',
        "Could not initialize monitoring for server {$server}: {$reason}"
    );
}

// ************************************************************************************************
// Controllers
// ************************************************************************************************
$cacheTypeResolver = new CacheTypeResolver();
$auditAggregator = new AuditAggregator();

$loginController = new LoginController($app, $tools, $twig, $csrf, $request);

$serverViewBuilder = new ServerViewBuilder(
    new ServerMonitor($log, $eventManager, $thresholds),
    $logSizeEvaluator,
    $thresholds,
    $log,
    $cacheTypeResolver,
    $monitoring->appInventorySync,
);
$dashboardController = new DashboardController(
    $app,
    $log,
    $appConfig,
    $notificationManager,
    $eventManager,
    $endpoints,
    $menuBuilder,
    $monitoringConfig,
    $serverMonitoringResult,
    DashboardRenderers::create($twig, $log, $tools, $app, $endpoints),
    $serverViewBuilder,
    new LibrariesViewBuilder($log, new AuditRowEnricher($auditAggregator)),
    new AlarmNotificationCenter($eventManager, $cacheStore, $cacheKeyFactory),
    $request,
);

$auditController = new AuditController(
    $log,
    new AuditRenderer($twig, $log, $tools, $app, $endpoints),
    $auditAggregator,
    $endpoints,
    $serverMonitoringResult->collectors(),
    $request,
);

$advisoryController = new AdvisoryController(
    $app,
    $log,
    $endpoints,
    $advisoryRuntime,
    new AdvisoriesRenderer($twig, $log, $tools, $app, $endpoints),
    new AdvisoriesViewBuilder($advisoryRuntime->config, $advisoryRuntime->advisories, $advisoryRuntime->states),
    $request,
);

$modalController = new ModalController(
    $log,
    new ModalRenderer($twig, $log, $tools, $app, $endpoints),
    new ModalCacheService($cacheTypeResolver),
    new LogDataTransformer($log),
    $endpoints,
    $serverMonitoringResult->collectors(),
    $request,
);

$metricsQuery = (new ServerMetricsQueryFactory($appConfig->environment->isDemo(), $db, $idResolver))->create();
$metricsViewBuilder = new MetricsViewBuilder($metricsQuery, $log);
$metricsRenderer = new MetricsRenderer($twig, $log, $tools, $app, $endpoints);
$chartViewBuilder = new ChartViewBuilder(
    $metricsViewBuilder,
    $metricsRenderer,
    new ChartDataTransformer(),
    $serversConfig
);

$metricsController = new MetricsController(
    $log,
    $metricsViewBuilder,
    $metricsRenderer,
    new MetricsCacheService($cacheStore, $cacheKeyFactory, $log, $serversConfig),
    new MetricsRequestValidator($metricCatalog, $metricQueryPolicy),
    $metricQueryPolicy,
    $chartViewBuilder,
    $metricCatalog,
    $serversConfig,
    $request,
    $monitoringConfig->charts->dashboardOrDefault($metricQueryPolicy),
    $monitoringConfig->ttl->get('metrics'),
    $monitoringConfig->ttl->get('serverMetricsChart'),
);

// ************************************************************************************************
// Router Setup
// ************************************************************************************************
$routerSetup = new RouterSetup(
    new Router(),
    $app,
    $loginController,
    $dashboardController,
    $auditController,
    $advisoryController,
    $modalController,
    $metricsController,
    $endpoints,
    $csrf,
    $twig,
    $log,
    $request,
);

$routerSetup->setup();
$routerSetup->run();
