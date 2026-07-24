
<?php

use Bramus\Router\Router;
use Mata\Config\DatabaseIntegrityChecker;
use Mata\Config\EnvConfigFatalException;
use Mata\Config\EnvConfigManager;
use Mata\Config\LogConfigurator;
use Mata\Config\Monitoring\AlarmLimitsConfigLoader;
use Mata\Config\Monitoring\DataRetentionConfigLoader;
use Mata\Config\Monitoring\IntervalsConfigLoader;
use Mata\Config\Monitoring\MenuConfigLoader;
use Mata\Config\Monitoring\MonitoringConfigManager;
use Mata\Config\Monitoring\NotificationRulesConfigLoader;
use Mata\Config\Monitoring\ProbesConfigLoader;
use Mata\Config\Monitoring\ServersConfigLoader;
use Mata\Config\Monitoring\ThresholdsConfigLoader;
use Mata\Config\Monitoring\TtlConfigLoader;
use Mata\Controllers\AuditController;
use Mata\Controllers\DashboardController;
use Mata\Controllers\LoginController;
use Mata\Controllers\MetricsController;
use Mata\Controllers\ModalController;
use Mata\Core\App;
use Mata\Core\Auth;
use Mata\Core\Database;
use Mata\Core\ExceptionHandler;
use Mata\Core\NullDatabase;
use Mata\Http\Routing\Endpoints;
use Mata\Http\Routing\RouterSetup;
use Mata\Http\Security\CsrfGuard;
use Mata\Http\Security\RouteGroupRateLimiterFactory;
use Mata\Http\Security\SecurityHeaders;
use Mata\Models\AlarmDeliveryRepository;
use Mata\Models\AlarmRepository;
use Mata\Models\AppFatalStateRepository;
use Mata\Models\ConfigSyncRepository;
use Mata\Models\EventRepository;
use Mata\Services\ServerMetrics\ServerMetricsQueryFactory;
use Mata\Services\Identity\ServerApplicationIdResolver;
use Mata\Services\Alarm\AlarmCreator;
use Mata\Services\Alarm\AlarmDirectProcessor;
use Mata\Services\Alarm\AlarmQueueFailureThrottle;
use Mata\Services\Cache\ApiCacheKeyFactory;
use Mata\Services\Cache\CacheStoreInterface;
use Mata\Services\Cache\CacheTtlResolver;
use Mata\Services\Cache\FileCacheStore;
use Mata\Services\Cache\MetricsCacheService;
use Mata\Services\DataTransformers\ApiResponseTransformer;
use Mata\Services\DataTransformers\ChartDataTransformer;
use Mata\Services\DataTransformers\LogDataTransformer;
use Mata\Services\Event\EventManager;
use Mata\Services\Event\EventRecorderService;
use Mata\Services\Monitor\Dto\AppReachabilityPolicy;
use Mata\Services\Monitor\Dto\DashboardMonitoringContext;
use Mata\Services\Monitor\HttpProbeClient;
use Mata\Services\Monitor\LogSizeEvaluator;
use Mata\Services\Monitor\ServerMonitoringFactory;
use Mata\Services\Navigation\MenuBuilder;
use Mata\Services\Notification\NotificationManager;
use Mata\Services\Runtime\DashboardEnvironmentFactory;
use Mata\Services\Sync\AppInventorySync;
use Mata\Services\Sync\SettingsDatabaseSync;
use Mata\Services\Validation\MetricsRequestValidator;
use Mata\Services\View\ChartViewBuilder;
use Mata\Services\View\MetricsViewBuilder;
use Mata\Utils\PathResolver;
use Mata\Utils\Tools;
use Mata\Utils\UsersFilePathResolver;
use Mata\Views\MetricsRenderer;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

// ************************************************************************************************
// Composer
// ************************************************************************************************
require_once '../vendor/autoload.php';

// ************************************************************************************************
// Path & Tools Initialization
// ************************************************************************************************
$paths = new PathResolver(__FILE__);
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
// Session Path
// ************************************************************************************************
$sessionPath = session_save_path($paths->appPath . '/temp/sessions');

// ************************************************************************************************
// Template Engine (Twig)
// ************************************************************************************************
$loader = new FilesystemLoader($paths->appPath . '/templates');
$twig = new Environment($loader, []);

// Configuration failures occur before App initialization; derive the public asset path from the request.
$scriptDirectory = dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php');
$errorAssetBaseUrl = ($scriptDirectory === '/' ? '' : rtrim($scriptDirectory, '/')) . '/assets';

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

// ************************************************************************************************
// HTTP Security Headers
// ************************************************************************************************
SecurityHeaders::apply($appConfig->general->securityHeadersEnabled);

// ************************************************************************************************
// Setting Environment & configurable Paths
// ************************************************************************************************
$environment = $appConfig->environment->env;
$paths->applyConfig($appConfig);

// ************************************************************************************************
// Apply Configured Monolog Settings
// ************************************************************************************************
$log = $logConfigurator->createConfiguredLogger($appConfig->logging, $paths, 'app');
ini_set('error_log', $paths->logsPath . '/php-error.log');
ini_set('display_errors', 0);

// ************************************************************************************************
// Instance Branding (app name + assets)
// ************************************************************************************************
// Seed public/branding/ with default assets on first run; instance assets placed there
// override the defaults and are gitignored (see public/branding/README.md).
$brandingDir = $paths->appPath . '/public/branding';
$brandingDefaultsDir = $paths->appPath . '/public/assets/branding';
$brandingAssets = [];
foreach (['logo' => 'logo.png', 'logo_topbar' => 'logo_topbar.png'] as $key => $file) {
    if (!file_exists($brandingDir . '/' . $file) && is_file($brandingDefaultsDir . '/' . $file)) {
        if (!is_dir($brandingDir)) {
            @mkdir($brandingDir, 0755, true);
        }
        if (!@copy($brandingDefaultsDir . '/' . $file, $brandingDir . '/' . $file)) {
            $log->warning('Could not seed default branding file: public/branding/' . $file);
        }
    }
    $brandingAssets[$key] = file_exists($brandingDir . '/' . $file)
        ? 'branding/' . $file
        : 'assets/branding/' . $file;
}

$favicon = ['path' => 'assets/images/favicon.png', 'type' => 'image/png'];
foreach (['favicon.svg' => 'image/svg+xml', 'favicon.png' => 'image/png'] as $file => $type) {
    if (is_file($brandingDir . '/' . $file)) {
        $favicon = ['path' => 'branding/' . $file, 'type' => $type];

        break;
    }

    if (is_file($brandingDefaultsDir . '/' . $file)) {
        if (!is_dir($brandingDir)) {
            @mkdir($brandingDir, 0755, true);
        }
        if (@copy($brandingDefaultsDir . '/' . $file, $brandingDir . '/' . $file)) {
            $favicon = ['path' => 'branding/' . $file, 'type' => $type];
        } else {
            $log->warning('Could not seed default branding file: public/branding/' . $file);
            $favicon = ['path' => 'assets/branding/' . $file, 'type' => $type];
        }

        break;
    }
}

$twig->addGlobal('branding', [
    'app_name' => $appConfig->general->appName,
    'logo' => $brandingAssets['logo'],
    'logo_topbar' => $brandingAssets['logo_topbar'],
    'favicon' => $favicon['path'],
    'favicon_type' => $favicon['type'],
]);

// ************************************************************************************************
// Global Exception Handler
// ************************************************************************************************
$exceptionHandler = new ExceptionHandler($log, $appConfig->template->debugEnabled);
// $exceptionHandler->register();

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
    $auth = new Auth($log, $usersFilePath);
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
$routeGroupRateLimiter = RouteGroupRateLimiterFactory::create($appConfig->rateLimiting, $log);
$app = new App($auth, $appConfig, $paths, $routeGroupRateLimiter);
$app->setBaseUrl($appConfig->environment->domain);
$csrf = new CsrfGuard();
$twig->addGlobal('csrf', $csrf);

// ************************************************************************************************
// Database Setup
// ************************************************************************************************
try {
    $db = new Database($appConfig->database);

    $databaseProbe = new DatabaseIntegrityChecker($db);

    try {
        $databaseProbe->assertReady();
    } catch (Throwable $probeException) {
        $log->error('Database quick probe failed; falling back to NullDatabase. ' . $probeException->getMessage());
        $log->debug('Database quick probe failure details', ['exception' => $probeException]);
        $db = new NullDatabase($log);
    }
} catch (Exception $e) {
    $log->error('Failed to initialize DB Connection: ' . $e->getMessage());
    $db = new NullDatabase($log);
}

// ************************************************************************************************
// Notification Manager & Config Warnings
// ************************************************************************************************
$notificationManager = new NotificationManager($db, $log, $paths);

foreach ($validationWarnings as $warning) {
    $notificationManager->addConfigWarning($warning['identifier'], $warning['message']);
}

$notificationManager->cleanupResolvedConfigWarnings($validationWarnings);
$notificationManager->cleanupOldNotifications(30); // delete old notifications (> 30 Days)

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

// --- Settings Sync ---
$settingsSync = new SettingsDatabaseSync($db, $log, $configSyncRepository);

// --- Tools & Resolvers ---
$idResolver = new ServerApplicationIdResolver($configSyncRepository);
$appInventorySync = new AppInventorySync($configSyncRepository, $idResolver, $log);

// --- Limits & Locking ---
$queueFailureThrottle = new AlarmQueueFailureThrottle($paths->tempPath . '/alarm-locks', $log);
$limitsLoader = new AlarmLimitsConfigLoader($log);
$limitsConfig = $limitsLoader->load($paths->appPath . '/config/limits.json');

// --- Alarm Manager ---
$alarmDirectProcessor = new AlarmDirectProcessor($alarmCreator, $limitsConfig, $queueFailureThrottle, $log);

// --- Event Manager ---
$eventRepository = new EventRepository($db);
$eventRecorder = new EventRecorderService($db, $idResolver);
$dashboardEnvironmentFactory = new DashboardEnvironmentFactory(
    $environment,
    $eventRepository,
    $alarmRepository,
    $idResolver,
    $log,
    $paths->appPath . '/config/demo/events.json',
);
$eventAggregator = $dashboardEnvironmentFactory->createEventAggregator();

$eventManager = new EventManager(
    $appConfig,
    $alarmDirectProcessor,
    $eventRecorder,
    $eventAggregator,
);

// ************************************************************************************************
// Monitoring Configuration
// ************************************************************************************************
$monitoringConfigManager = new MonitoringConfigManager([
    new ServersConfigLoader(
        eventRecorder: $eventManager->recorder,
        alarmDirectProcessor: $alarmDirectProcessor,
        db: $db,
        settingsSync: $settingsSync,
        fallbackOnFailure: true,
    ),
    new TtlConfigLoader(eventRecorder: $eventManager->recorder, fallbackOnFailure: true),
    new DataRetentionConfigLoader(eventRecorder: $eventManager->recorder, fallbackOnFailure: true),
    new IntervalsConfigLoader(eventRecorder: $eventManager->recorder, fallbackOnFailure: true),
    new ProbesConfigLoader(eventRecorder: $eventManager->recorder, fallbackOnFailure: true),
    new NotificationRulesConfigLoader(eventRecorder: $eventManager->recorder, fallbackOnFailure: true),
    new MenuConfigLoader(
        eventRecorder: $eventManager->recorder,
        validKeys: MenuBuilder::MENU_KEYS,
        fallbackOnFailure: true,
    ),
    new ThresholdsConfigLoader(
        eventRecorder: $eventManager->recorder,
        alarmDirectProcessor: $alarmDirectProcessor,
        fallbackOnFailure: true,
    ),
]);

$configPath = $paths->appPath . '/config';
$log->info("Loading monitoring configuration for environment: {$environment}");
$monitoringSettings = $monitoringConfigManager->loadAllConfigs($configPath, $environment);
$eventManager->setMonitoringSettings($monitoringSettings);
$idResolver->refreshFromRepository($monitoringSettings['servers'] ?? []);

$serverSettings = $monitoringSettings['servers'] ?? [];

// ************************************************************************************************
// Cache & Endpoints
// ************************************************************************************************
/** @var CacheStoreInterface $cacheStore */
$cacheStore = new FileCacheStore($log, $paths->tempPath . '/api-cache');
$cacheKeyFactory = new ApiCacheKeyFactory();
$ttlResolver = new CacheTtlResolver($monitoringSettings['ttl']);
$endpoints = new Endpoints();
$menuBuilder = new MenuBuilder($endpoints, $tools, $monitoringSettings['menu'] ?? []);

// ************************************************************************************************
// Server Monitoring Services (live API or demo mocks)
// ************************************************************************************************
$monitoringPolicy = AppReachabilityPolicy::fromSettings($monitoringSettings);
$serverMonitoringFactory = new ServerMonitoringFactory(
    $log,
    $cacheStore,
    $cacheKeyFactory,
    $ttlResolver,
    $eventManager,
    new ApiResponseTransformer($log),
    new HttpProbeClient(),
    $monitoringPolicy,
    $idResolver,
    $fatalStateRepository,
    new LogSizeEvaluator($tools, $eventManager),
);

$serverMonitoringBuild = $dashboardEnvironmentFactory->createMonitoring(
    $serverSettings,
    $serverMonitoringFactory,
);

foreach ($serverMonitoringBuild->failures as $server => $reason) {
    $notificationManager->addConfigWarning(
        'server.monitoring.failed',
        "Could not initialize monitoring for server {$server}: {$reason}"
    );
}

$dashboardMonitoringContext = DashboardMonitoringContext::fromSettingsAndServices(
    $monitoringSettings,
    $serverMonitoringBuild->servicesByServer,
);

// ************************************************************************************************
// Controllers
// ************************************************************************************************
$loginController = new LoginController($app, $tools, $twig);
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
    $dashboardMonitoringContext,
    $appInventorySync,
);

$logDataTransformer = new LogDataTransformer($log);

$auditController = new AuditController(
    $app,
    $tools,
    $twig,
    $log,
    $appConfig,
    $cacheStore,
    $cacheKeyFactory,
    $ttlResolver,
    $endpoints,
    $serverSettings,
);

$modalController = new ModalController(
    $app,
    $tools,
    $twig,
    $log,
    $appConfig,
    $cacheStore,
    $cacheKeyFactory,
    $ttlResolver,
    $logDataTransformer,
    $endpoints,
    $serverSettings,
);

$metricsQueryFactory = new ServerMetricsQueryFactory($environment, $db, $idResolver);
$metricsQuery = $metricsQueryFactory->create();
$metricsViewBuilder = new MetricsViewBuilder($metricsQuery, $log);
$metricsRenderer = new MetricsRenderer($twig, $log, $tools, $app, $endpoints);
$metricsRequestValidator = new MetricsRequestValidator(
    MetricsController::SUPPORTED_METRICS,
    MetricsController::ALLOWED_GRANULARITIES
);
$metricsDashboardConfig = isset($monitoringSettings['charts']['dashboard']) && is_array($monitoringSettings['charts']['dashboard'])
    ? $monitoringSettings['charts']['dashboard']
    : [];
$metricsDashboardTtl = isset($monitoringSettings['ttl']['serverMetricsChart'])
    ? (int) $monitoringSettings['ttl']['serverMetricsChart']
    : null;

// Metrics Services
$metricsCacheService = new MetricsCacheService(
    $cacheStore,
    $cacheKeyFactory,
    $log,
    $serverSettings
);
$chartDataTransformer = new ChartDataTransformer();
$chartViewBuilder = new ChartViewBuilder(
    $metricsViewBuilder,
    $metricsRenderer,
    $metricsRequestValidator,
    $chartDataTransformer,
    $serverSettings
);

$metricsController = new MetricsController(
    $log,
    $metricsViewBuilder,
    $metricsRenderer,
    $metricsCacheService,
    $metricsRequestValidator,
    $chartViewBuilder,
    $serverSettings,
    isset($monitoringSettings['ttl']['metrics']) ? (int) $monitoringSettings['ttl']['metrics'] : null,
    $metricsDashboardConfig,
    $metricsDashboardTtl
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
    $csrf
);

$routerSetup->setup();
$routerSetup->run();
