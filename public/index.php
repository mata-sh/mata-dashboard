<?php

declare(strict_types=1);

use Bramus\Router\Router;
use Mata\Bootstrap\BrandingResolver;
use Mata\Bootstrap\DatabaseBootstrap;
use Mata\Config\EnvConfigFatalException;
use Mata\Config\EnvConfigManager;
use Mata\Config\LogConfigurator;
use Mata\Config\Monitoring\Loader\AlarmRateLimitsConfigLoader;
use Mata\Config\Monitoring\Loader\ChartsConfigLoader;
use Mata\Config\Monitoring\Loader\DataRetentionConfigLoader;
use Mata\Config\Monitoring\Loader\IntervalsConfigLoader;
use Mata\Config\Monitoring\Loader\MenuConfigLoader;
use Mata\Config\Monitoring\Loader\NotificationRulesConfigLoader;
use Mata\Config\Monitoring\Loader\ProbesConfigLoader;
use Mata\Config\Monitoring\Loader\ServersConfigLoader;
use Mata\Config\Monitoring\Loader\ThresholdsConfigLoader;
use Mata\Config\Monitoring\Loader\TtlConfigLoader;
use Mata\Config\Monitoring\MonitoringConfigLoader;
use Mata\Controllers\AuditController;
use Mata\Controllers\DashboardController;
use Mata\Controllers\LoginController;
use Mata\Controllers\MetricsController;
use Mata\Controllers\ModalController;
use Mata\Core\App;
use Mata\Core\Auth;
use Mata\Core\ExceptionHandler;
use Mata\Core\Session;
use Mata\Http\Request;
use Mata\Http\Routing\Endpoints;
use Mata\Http\Routing\RouterSetup;
use Mata\Http\Security\CsrfGuard;
use Mata\Http\Security\RateLimiting\RequestRateLimiterFactory;
use Mata\Http\Security\SecurityHeaders;
use Mata\Models\AlarmDeliveryRepository;
use Mata\Models\AlarmRepository;
use Mata\Models\AppFatalStateRepository;
use Mata\Models\ConfigSyncRepository;
use Mata\Models\EventRepository;
use Mata\Services\Alarm\AlarmCreator;
use Mata\Services\Alarm\AlarmDirectProcessor;
use Mata\Services\Alarm\AlarmQueueFailureThrottle;
use Mata\Services\Cache\ApiCacheKeyFactory;
use Mata\Services\Cache\CacheTtlResolver;
use Mata\Services\Cache\FileCacheStore;
use Mata\Services\Cache\MetricsCacheService;
use Mata\Services\Collect\NodeResponseNormalizer;
use Mata\Services\DataTransformers\ChartDataTransformer;
use Mata\Services\DataTransformers\LogDataTransformer;
use Mata\Services\Event\EventManager;
use Mata\Services\Event\EventRecorderService;
use Mata\Services\Identity\ServerApplicationIdResolver;
use Mata\Services\Monitor\Dto\AppReachabilityPolicy;
use Mata\Services\Monitor\HttpProbeClient;
use Mata\Services\Monitor\LogSizeEvaluator;
use Mata\Services\Monitor\ServerMonitoringFactory;
use Mata\Services\Navigation\MenuBuilder;
use Mata\Services\Notification\SystemNotificationManager;
use Mata\Services\Runtime\DashboardEnvironmentFactory;
use Mata\Services\ServerMetrics\MetricCatalog;
use Mata\Services\ServerMetrics\MetricQueryPolicy;
use Mata\Services\ServerMetrics\MetricsRequestValidator;
use Mata\Services\ServerMetrics\ServerMetricsQueryFactory;
use Mata\Services\Sync\AppInventorySync;
use Mata\Services\Sync\ServerConfigDatabaseSync;
use Mata\Services\View\ChartViewBuilder;
use Mata\Services\View\MetricsViewBuilder;
use Mata\Utils\PathResolver;
use Mata\Utils\Tools;
use Mata\Utils\UsersFilePathResolver;
use Mata\Views\MetricsRenderer;
use Monolog\ErrorHandler as MonologErrorHandler;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

// ************************************************************************************************
// Composer
// ************************************************************************************************
require_once '../vendor/autoload.php';

$request = Request::fromGlobals();

// ************************************************************************************************
// Path & Tools Initialization
// ************************************************************************************************
$paths = PathResolver::fromEntryPoint(__FILE__);
$tools = new Tools($paths->appPath);

// ************************************************************************************************
// Error Reporting & Logging
// ************************************************************************************************
ini_set('display_errors', 1);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', $paths->bootLogPath);

// ************************************************************************************************
// Monolog (pre config loading -> boot.log)
// ************************************************************************************************
$logConfigurator = new LogConfigurator();
$log = $logConfigurator->createBootLogger($paths);

// ************************************************************************************************
// Template Engine (Twig)
// ************************************************************************************************
$loader = new FilesystemLoader($paths->appPath . '/templates');
$twig = new Environment($loader, ['autoescape' => 'html']);

// Configuration failures occur before App initialization; derive the public asset path from the request.
$errorAssetBaseUrl = rtrim($request->scriptDirectory(), '/') . '/assets';

// ************************************************************************************************
// Config Initialization
// ************************************************************************************************
try {
    $initResult = (new EnvConfigManager(
        configDir: $paths->appPath . '/config',
        exampleDir: $paths->appPath . '/config/example',
        requiredFiles: [
            'config.env',
            'db.config.env',
            'mail.config.env',
        ],
        logger: $log
    ))->initialize();

    $appConfig = $initResult->config;
    $validationWarnings = $initResult->warnings;
} catch (EnvConfigFatalException $e) {
    $log->critical($e->getMessage(), ['context' => $e->getContext()]);

    http_response_code(500);
    echo $twig->render('partials/errors/config-error.html.twig', [
        'asset_base_url' => $errorAssetBaseUrl,
        'error' => [
            'title' => $e->getTitle(),
            'heading' => $e->getHeading(),
            'type' => $e->getType(),
            'message' => $e->getUserMessage(),
            'context' => $e->getContext(),
            'documentation_url' => $e->getDocumentationUrl(),
        ],
    ]);

    exit(1);
} catch (Throwable $e) {
    $log->critical('Application Initialization failed! ' . $e->getMessage(), ['exception' => $e]);

    exit('Application initialization failed!');
}

$request = $request->withTrustedProxies($appConfig->general->trustedProxies);

// ************************************************************************************************
// HTTP Security Headers
// ************************************************************************************************
SecurityHeaders::apply(
    secure: $request->isSecure(),
    enabled: $appConfig->general->securityHeadersEnabled,
    hstsEnabled: $appConfig->general->hstsEnabled,
);

// ************************************************************************************************
// Setting Environment & configurable Paths
// ************************************************************************************************
$environment = $appConfig->environment->env;
$paths->applyConfig($appConfig);

// ************************************************************************************************
// Configure Monolog
// ************************************************************************************************
$log = $logConfigurator->createConfiguredLogger($appConfig->logging, $paths, 'app');
ini_set('error_log', $paths->logsPath . '/php-error.log');
ini_set('display_errors', 0);

// ************************************************************************************************
// Global Error and Session Handling
// ************************************************************************************************
$exceptionHandler = new ExceptionHandler($log, $request, $appConfig->template->debugEnabled);
$exceptionHandler->register();
(new MonologErrorHandler($log))
    ->registerErrorHandler(callPrevious: true)
    ->registerFatalHandler();
Session::start($paths->tempPath . '/sessions', $request->isSecure());

// ************************************************************************************************
// Instance Branding (app name + assets)
// ************************************************************************************************
// Seed public/branding/ with default assets on first run; instance assets placed there
// override the defaults and are gitignored (see public/branding/README.md).
$brandingAssets = (new BrandingResolver(
    $paths->appPath . '/public/branding',
    $paths->appPath . '/public/assets/branding',
    $log,
))->resolve();

$twig->addGlobal('branding', [
    'app_name' => $appConfig->general->appName,
    'logo' => $brandingAssets['logo'],
    'logo_topbar' => $brandingAssets['logo_topbar'],
    'favicon' => $brandingAssets['favicon'],
    'favicon_type' => $brandingAssets['favicon_type'],
]);

// ************************************************************************************************
// Template Engine (Twig)
// ************************************************************************************************
$templateCacheDir = $paths->tempPath . '/templates-cache';
$twig->setCache($appConfig->template->debugEnabled ? $templateCacheDir : false);
if ($appConfig->template->debugEnabled) {
    $twig->enableDebug();
    $twig->enableAutoReload();
}

// ************************************************************************************************
// Initialize App & Authentication
// ************************************************************************************************
$usersFilePath = null;

try {
    $usersFilePath = UsersFilePathResolver::resolve($paths->appPath);
    $auth = new Auth(
        $log,
        $usersFilePath,
        $request->isSecure(),
        $request->cookie('remember_token'),
    );
} catch (Throwable $e) {
    $log->critical('Authentication initialization failed: ' . $e->getMessage());

    http_response_code(500);
    echo $twig->render('partials/errors/auth-error.html.twig', [
        'asset_base_url' => $errorAssetBaseUrl,
        'error' => [
            'title' => 'Authentication Error',
            'heading' => 'Authentication System Unavailable',
            'type' => 'error',
            'message' => 'The authentication system could not be initialized.',
            'technical_details' => $appConfig->template->debugEnabled ? $e->getMessage() : null,
            'suggestions' => [
                $usersFilePath === null
                    ? 'Set MATA_USERS_FILE to an absolute path outside the application directory'
                    : 'Ensure users.json exists in: ' . dirname($usersFilePath),
                'Use the make command to create a new user if needed',
                'Ensure the PHP process can read the file and write its directory',
                'Check file permissions (should be 600)',
                'Verify JSON file is valid',
            ],
            'documentation_url' => 'https://docs.mata.sh/authentication', // TODO: Add documentation URL for authentication troubleshooting
        ],
    ]);

    exit(1);
}

// ************************************************************************************************
// Rate Limiter Setup
// ************************************************************************************************
$requestRateLimiter = RequestRateLimiterFactory::create($appConfig->rateLimiting, $log);
$app = new App($auth, $appConfig, $paths, $request, $requestRateLimiter);
$csrf = new CsrfGuard($request);
$twig->addGlobal('csrf', $csrf);

// ************************************************************************************************
// Database Setup
// ************************************************************************************************
$db = (new DatabaseBootstrap($appConfig->database, $log))->initialize();

// ************************************************************************************************
// Notification Manager & Config Warnings
// ************************************************************************************************
$notificationManager = new SystemNotificationManager($db, $log, $paths);

foreach ($validationWarnings as $warning) {
    $notificationManager->addConfigWarning($warning['identifier'], $warning['message']);
}

// ************************************************************************************************
// Initialize Mailer
// ************************************************************************************************
$transport = Transport::fromDsn($appConfig->mail->dsn);
$mailer = new Mailer($transport);

$mailCfg = [
    'from_email' => $appConfig->mail->fromEmail,
    'from_name' => $appConfig->mail->fromName,
    'recipients' => $appConfig->mail->alarmRecipients,
];

// ************************************************************************************************
// Core Infrastructure
// ************************************************************************************************

// --- Repositories ---
$configSyncRepository = new ConfigSyncRepository($db);
$fatalStateRepository = new AppFatalStateRepository($db);
$alarmRepository = new AlarmRepository($db);
$deliveryRepository = new AlarmDeliveryRepository($db);
$alarmCreator = new AlarmCreator($db, $alarmRepository, $deliveryRepository);

// --- Tools & Resolvers ---
$idResolver = new ServerApplicationIdResolver($configSyncRepository);
$appInventorySync = new AppInventorySync($configSyncRepository, $idResolver, $log);

// --- Event Manager ---
// Early initialization for config loaders.
// Event aggregator and monitoring config are attached once they exist.
$eventRepository = new EventRepository($db);
$eventRecorder = new EventRecorderService($db, $idResolver);
$eventManager = new EventManager($appConfig, eventRecorder: $eventRecorder, logger: $log);

// --- Limits & Locking ---
$queueFailureThrottle = new AlarmQueueFailureThrottle($paths->tempPath . '/alarm-locks', $log);
$limitsLoader = new AlarmRateLimitsConfigLoader($eventManager);
$limitsConfig = $limitsLoader->load($paths->appPath . '/config');

// --- Alarm Manager ---
$alarmDirectProcessor = new AlarmDirectProcessor($alarmCreator, $limitsConfig, $queueFailureThrottle, $log);
$eventManager->setAlarmDirectProcessor($alarmDirectProcessor);

// --- Event Aggregation ---
$dashboardEnvironmentFactory = new DashboardEnvironmentFactory(
    $environment,
    $eventRepository,
    $alarmRepository,
    $idResolver,
    $log,
    $paths->appPath . '/config/demo/events.json',
);
$eventAggregator = $dashboardEnvironmentFactory->createEventAggregator();
$eventManager->setEventAggregator($eventAggregator);

// ************************************************************************************************
// Monitoring Configuration
// ************************************************************************************************
$metricCatalog = new MetricCatalog();
$metricQueryPolicy = new MetricQueryPolicy();
$metricsRequestValidator = new MetricsRequestValidator($metricCatalog, $metricQueryPolicy);

$monitoringConfigLoader = new MonitoringConfigLoader(
    servers: new ServersConfigLoader(
        events: $eventManager,
        fallbackOnFailure: true,
    ),
    ttl: new TtlConfigLoader(
        events: $eventManager,
        fallbackOnFailure: true,
    ),
    dataRetention: new DataRetentionConfigLoader(
        events: $eventManager,
        fallbackOnFailure: true,
    ),
    intervals: new IntervalsConfigLoader(
        events: $eventManager,
        fallbackOnFailure: true,
    ),
    probes: new ProbesConfigLoader(
        events: $eventManager,
        fallbackOnFailure: true,
    ),
    notificationRules: new NotificationRulesConfigLoader(
        events: $eventManager,
        fallbackOnFailure: true,
    ),
    thresholds: new ThresholdsConfigLoader(
        events: $eventManager,
        fallbackOnFailure: true,
    ),
    menu: new MenuConfigLoader(
        events: $eventManager,
        validKeys: MenuBuilder::MENU_KEYS,
        fallbackOnFailure: true,
    ),
    charts: new ChartsConfigLoader(
        events: $eventManager,
        metricCatalog: $metricCatalog,
        queryPolicy: $metricQueryPolicy,
        fallbackOnFailure: true,
    ),
);

$configPath = $paths->appPath . '/config';
$log->info("Loading monitoring configuration for environment: {$environment}");
$monitoringConfig = $monitoringConfigLoader->load($configPath, $limitsConfig);
$eventManager->setMonitoringConfig($monitoringConfig);

// Persist servers.json before server IDs are resolved from the database. Web only: cron reads what this wrote.
$serverConfigSync = new ServerConfigDatabaseSync($db, $log, $eventManager, $configSyncRepository);
$serverConfigSync->syncIfNeeded($configPath . '/servers.json', $monitoringConfig->servers->values);
$idResolver->refreshFromRepository($monitoringConfig->servers->values);

$serversConfig = $monitoringConfig->servers->values;

// ************************************************************************************************
// Cache & Endpoints
// ************************************************************************************************
$cacheStore = new FileCacheStore($log, $paths->tempPath . '/api-cache');
$cacheKeyFactory = new ApiCacheKeyFactory();
$ttlResolver = new CacheTtlResolver($monitoringConfig->ttl);
$endpoints = new Endpoints();
$menuBuilder = new MenuBuilder($endpoints, $tools, $monitoringConfig->menu->overrides);

// ************************************************************************************************
// Server Monitoring Services (live API or demo mocks)
// ************************************************************************************************
$monitoringPolicy = AppReachabilityPolicy::fromMonitoringConfig($monitoringConfig);
$serverMonitoringFactory = new ServerMonitoringFactory(
    $log,
    $cacheStore,
    $cacheKeyFactory,
    $ttlResolver,
    $eventManager,
    new NodeResponseNormalizer($log),
    new HttpProbeClient(),
    $monitoringPolicy,
    $idResolver,
    $fatalStateRepository,
    new LogSizeEvaluator($tools, $eventManager),
);

$serverMonitoringResult = $dashboardEnvironmentFactory->createMonitoring(
    $serversConfig,
    $serverMonitoringFactory,
);

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
    $appInventorySync,
);

$logDataTransformer = new LogDataTransformer($log);

$auditController = new AuditController(
    $app,
    $tools,
    $twig,
    $log,
    $endpoints,
    $serverMonitoringResult->collectors(),
    $request,
);

$modalController = new ModalController(
    $app,
    $tools,
    $twig,
    $log,
    $logDataTransformer,
    $endpoints,
    $serverMonitoringResult->collectors(),
    $request,
);

$metricsQueryFactory = new ServerMetricsQueryFactory($environment, $db, $idResolver);
$metricsQuery = $metricsQueryFactory->create();
$metricsViewBuilder = new MetricsViewBuilder($metricsQuery, $log);
$metricsRenderer = new MetricsRenderer($twig, $log, $tools, $app, $endpoints);
$metricsDashboardConfig = $monitoringConfig->charts->dashboardOrDefault($metricQueryPolicy);
$metricsDashboardTtl = $monitoringConfig->ttl->get('serverMetricsChart');

// Metrics Services
$metricsCacheService = new MetricsCacheService(
    $cacheStore,
    $cacheKeyFactory,
    $log,
    $serversConfig
);
$chartDataTransformer = new ChartDataTransformer();
$chartViewBuilder = new ChartViewBuilder(
    $metricsViewBuilder,
    $metricsRenderer,
    $chartDataTransformer,
    $serversConfig
);

$metricsController = new MetricsController(
    $log,
    $metricsViewBuilder,
    $metricsRenderer,
    $metricsCacheService,
    $metricsRequestValidator,
    $metricQueryPolicy,
    $chartViewBuilder,
    $metricCatalog,
    $serversConfig,
    $request,
    $metricsDashboardConfig,
    $monitoringConfig->ttl->get('metrics'),
    $metricsDashboardTtl,
);

// ************************************************************************************************
// Router Setup
// ************************************************************************************************
$router = new Router();

$routerSetup = new RouterSetup(
    $router,
    $app,
    $loginController,
    $dashboardController,
    $auditController,
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
