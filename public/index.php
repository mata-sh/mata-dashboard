
<?php

use Bramus\Router\Router;
use Mata\Config\AlarmLimitsLoader;
use Mata\Config\DatabaseIntegrityChecker;
use Mata\Config\EnvConfigFatalException;
use Mata\Config\EnvConfigManager;
use Mata\Config\LogConfigurator;
use Mata\Config\MonitoringSettingsLoader;
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
use Mata\Factory\RateLimiterFactory;
use Mata\Factory\ServerMetricsQueryFactory;
use Mata\Http\Routing\Endpoints;
use Mata\Http\Routing\RouterSetup;
use Mata\Http\Security\CsrfGuard;
use Mata\Http\SecurityHeaders;
use Mata\Models\AlarmDeliveryRepository;
use Mata\Models\AlarmRepository;
use Mata\Models\ConfigSyncRepository;
use Mata\Models\EventRepository;
use Mata\Services\Alarm\AlarmCreator;
use Mata\Services\Alarm\AlarmManager;
use Mata\Services\Cache\ApiCacheKeyFactory;
use Mata\Services\Cache\CacheStoreInterface;
use Mata\Services\Cache\CacheTtlResolver;
use Mata\Services\Cache\FileCacheStore;
use Mata\Services\Cache\MetricsCacheService;
use Mata\Services\DataTransformers\ChartDataTransformer;
use Mata\Services\DataTransformers\LogDataTransformer;
use Mata\Services\Event\EventAggregatorService;
use Mata\Services\Event\EventManager;
use Mata\Services\Event\EventRecorderService;
use Mata\Services\Event\MockEventAggregatorService;
use Mata\Services\Foundation\IdResolver;
use Mata\Services\Foundation\SettingsDatabaseSync;
use Mata\Services\Navigation\MenuBuilder;
use Mata\Services\Notification\NotificationManager;
use Mata\Services\Validation\MetricsRequestValidator;
use Mata\Services\View\ChartViewBuilder;
use Mata\Services\View\MetricsViewBuilder;
use Mata\Utils\ChannelLockManager;
use Mata\Utils\PathResolver;
use Mata\Utils\Tools;
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
// Instance Branding (app name + logos)
// ************************************************************************************************
// Seed public/branding/ with the default logos on first run; instance logos placed there
// override the defaults and are gitignored (see public/branding/README.md).
$brandingDir = $paths->appPath . '/public/branding';
$brandingDefaultsDir = $paths->appPath . '/public/assets/branding';
$brandingLogos = [];
foreach (['logo' => 'logo.png', 'logo_topbar' => 'logo_topbar.png'] as $key => $file) {
    if (!file_exists($brandingDir . '/' . $file) && is_file($brandingDefaultsDir . '/' . $file)) {
        if (!is_dir($brandingDir)) {
            @mkdir($brandingDir, 0755, true);
        }
        if (!@copy($brandingDefaultsDir . '/' . $file, $brandingDir . '/' . $file)) {
            $log->warning('Could not seed default branding file: public/branding/' . $file);
        }
    }
    $brandingLogos[$key] = file_exists($brandingDir . '/' . $file)
        ? 'branding/' . $file
        : 'assets/branding/' . $file;
}

$twig->addGlobal('branding', [
    'app_name'    => $appConfig->general->appName,
    'logo'        => $brandingLogos['logo'],
    'logo_topbar' => $brandingLogos['logo_topbar'],
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
try {
    $usersFilePath = $paths->appPath . '/config/' . $environment . '/users.json';
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
                'Ensure users.json exists in: ' . dirname($usersFilePath),
                'Use the make command to create a new user if needed',
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
$rateLimiterService = RateLimiterFactory::create($appConfig->rateLimiting, $log);
$app = new App($auth, $appConfig, $paths, $rateLimiterService);
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
$alarmRepository = new AlarmRepository($db);
$deliveryRepository = new AlarmDeliveryRepository($db);
$alarmCreator = new AlarmCreator($db, $alarmRepository, $deliveryRepository);

// --- Settings Sync ---
$settingsSync = new SettingsDatabaseSync($db, $log, $configSyncRepository);

// --- Tools & Resolvers ---
$idResolver = new IdResolver($configSyncRepository);

// --- Limits & Locking ---
$lockManager = new ChannelLockManager($paths->tempPath . '/alarm-locks', $log);
$limitsLoader = new AlarmLimitsLoader($log);
$limitsConfig = $limitsLoader->load($paths->appPath . '/config/limits.json');

// --- Alarm Manager ---
$alarmManager = new AlarmManager($alarmCreator, $limitsConfig, $lockManager, $log);

// --- Event Manager ---
$eventRepository = new EventRepository($db);
$eventRecorder = new EventRecorderService($db, $idResolver);

$useMockEvents = (getenv('MATA_EVENTS_MOCK') === '1' || $appConfig->environment->env === 'demo');
if ($useMockEvents) {
    $demoDataPath = getenv('MATA_EVENTS_MOCK_DATA_PATH') ?: $paths->appPath . '/config/demo/events.json';
    $demoData = MockEventAggregatorService::loadDemoData($demoDataPath, $log);
    if ($appConfig->environment->env === 'demo') {
        $demoData = MockEventAggregatorService::withNotificationShowcase($demoData);
    }
    $eventAggregator = new MockEventAggregatorService($log, $demoData);
    $log->info('Using mock event aggregator for demo data.', ['path' => $demoDataPath]);
} else {
    $eventAggregator = new EventAggregatorService($eventRepository, $alarmRepository, $idResolver, $log);
}

$eventManager = (new EventManager($appConfig, $alarmManager))
    ->setEventRecorder($eventRecorder)
    ->setEventAggregator($eventAggregator);

// ************************************************************************************************
// Monitoring Configuration
// ************************************************************************************************
$monitoringSettingsLoader = new MonitoringSettingsLoader($eventManager->recorder, $alarmManager, $db, $settingsSync);

try {
    $configPath = $paths->appPath . '/config';
    $log->info("Loading monitoring configuration for environment: {$environment}");
    $monitoringSettings = $monitoringSettingsLoader->loadAllConfigs($configPath, $environment);
    $eventManager->setMonitoringSettings($monitoringSettings);

    // Update ServerId->ServerName Map with servers.json content
    $idResolver->refreshFromRepository(
        $monitoringSettings['servers'] ?? [],
    );
} catch (Exception $e) {
    $log->error('Failed to load monitoring configuration: ' . $e->getMessage());
    // Use default configurations
    $monitoringSettings = [
        'servers' => [],
        'ttl' => $monitoringSettingsLoader->getDefaultTtlConfig(),
        'thresholds' => [],
        'events' => $monitoringSettingsLoader->getDefaultEventsConfig(),
        'environment' => 'prod',
    ];
}
// ************************************************************************************************
// Cache & Endpoints
// ************************************************************************************************
/** @var CacheStoreInterface $cacheStore */
$cacheStore = new FileCacheStore($log, $paths->tempPath . '/api-cache');
$cacheKeyFactory = new ApiCacheKeyFactory();
$ttlResolver = new CacheTtlResolver($monitoringSettings['ttl']);
$endpoints = new Endpoints();
$menuBuilder = new MenuBuilder($endpoints, $tools);

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
    $ttlResolver,
    $endpoints,
    $menuBuilder,
    $monitoringSettings,
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
    $monitoringSettings['servers'],
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
    $monitoringSettings['servers'],
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
    $monitoringSettings['servers'] ?? []
);
$chartDataTransformer = new ChartDataTransformer();
$chartViewBuilder = new ChartViewBuilder(
    $metricsViewBuilder,
    $metricsRenderer,
    $metricsRequestValidator,
    $chartDataTransformer,
    $monitoringSettings['servers'] ?? []
);

$metricsController = new MetricsController(
    $log,
    $metricsViewBuilder,
    $metricsRenderer,
    $metricsCacheService,
    $metricsRequestValidator,
    $chartViewBuilder,
    $monitoringSettings['servers'] ?? [],
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
