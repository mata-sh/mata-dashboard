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
use Mata\Services\Cache\ApiCacheKeyFactory;
use Mata\Services\Cache\CacheTtlResolver;
use Mata\Services\Cache\FileCacheStore;
use Mata\Services\Cache\MetricsCacheService;
use Mata\Services\Collect\NodeResponseNormalizer;
use Mata\Services\DataTransformers\ChartDataTransformer;
use Mata\Services\DataTransformers\LogDataTransformer;
use Mata\Services\Monitor\Dto\AppReachabilityPolicy;
use Mata\Services\Monitor\HttpProbeClient;
use Mata\Services\Monitor\LogSizeEvaluator;
use Mata\Services\Monitor\MetricThresholds;
use Mata\Services\Monitor\ServerMonitoringFactory;
use Mata\Services\Navigation\MenuBuilder;
use Mata\Services\ServerMetrics\MetricCatalog;
use Mata\Services\ServerMetrics\MetricQueryPolicy;
use Mata\Services\ServerMetrics\MetricsRequestValidator;
use Mata\Services\ServerMetrics\ServerMetricsQueryFactory;
use Mata\Services\View\ChartViewBuilder;
use Mata\Services\View\MetricsViewBuilder;
use Mata\Views\MetricsRenderer;

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
$environment = $appConfig->environment->env;

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
    new LogSizeEvaluator($tools, MetricThresholds::fromConfig($monitoringConfig->thresholds)),
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
$loginController = new LoginController($app, $tools, $twig, $csrf, $request);
$dashboardController = new DashboardController(
    $app,
    $tools,
    $twig,
    $log,
    $appConfig,
    $notificationManager,
    $eventManager,
    $cacheStore,
    $cacheKeyFactory,
    $endpoints,
    $menuBuilder,
    $monitoringConfig,
    $serverMonitoringResult,
    $request,
    $monitoring->appInventorySync,
);

$auditController = new AuditController(
    $app,
    $tools,
    $twig,
    $log,
    $endpoints,
    $serverMonitoringResult->collectors(),
    $request,
);

$advisoryController = new AdvisoryController(
    $app,
    $tools,
    $twig,
    $log,
    $endpoints,
    $advisoryRuntime,
    $request,
);

$modalController = new ModalController(
    $app,
    $tools,
    $twig,
    $log,
    new LogDataTransformer($log),
    $endpoints,
    $serverMonitoringResult->collectors(),
    $request,
);

$metricsQuery = (new ServerMetricsQueryFactory($environment, $db, $idResolver))->create();
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
