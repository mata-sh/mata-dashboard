
<?php

use Bramus\Router\Router;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Watcher\Config\AlertLimitsLoader;
use Watcher\Config\DatabaseIntegrityChecker;
use Watcher\Config\EnvConfigLoader;
use Watcher\Config\MonitoringSettingsLoader;
use Watcher\Controllers\DashboardController;
use Watcher\Controllers\LoginController;
use Watcher\Controllers\MetricsController;
use Watcher\Controllers\ModalController;
use Watcher\Core\App;
use Watcher\Core\Auth;
use Watcher\Core\Database;
use Watcher\Core\ExceptionHandler;
use Watcher\Core\NullDatabase;
use Watcher\Factory\ServerMetricsQueryFactory;
use Watcher\Http\Routing\Endpoints;
use Watcher\Http\Routing\RouterSetup;
use Watcher\Http\SecurityHeaders;
use Watcher\Models\AlertQueueRepository;
use Watcher\Models\ConfigSyncRepository;
use Watcher\Models\EventRecorder;
use Watcher\Services\Cache\MetricsCacheService;
use Watcher\Services\Foundation\AlertManager;
use Watcher\Services\Foundation\CacheManager;
use Watcher\Services\Foundation\DataTransformers\ChartDataTransformer;
use Watcher\Services\Foundation\EventManager;
use Watcher\Services\Foundation\IdResolver;
use Watcher\Services\Foundation\NotificationManager;
use Watcher\Services\Foundation\SettingsDatabaseSync;
use Watcher\Services\Navigation\MenuBuilder;
use Watcher\Services\Validation\MetricsRequestValidator;
use Watcher\Services\View\ChartViewBuilder;
use Watcher\Services\View\MetricsViewBuilder;
use Watcher\Utils\ChannelLockManager;
use Watcher\Utils\PathResolver;
use Watcher\Utils\Tools;
use Watcher\Views\MetricsRenderer;

// ************************************************************************************************
// Composer
// ************************************************************************************************
require_once '../vendor/autoload.php';

// ************************************************************************************************
// Path Initialization
// ************************************************************************************************
$paths = new PathResolver(__FILE__);

// ************************************************************************************************
// Error Reporting & Logging
// ************************************************************************************************
ini_set('display_errors', 1);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', $paths->logsPath . '/phperror.log');

// ************************************************************************************************
// Session Path
// ************************************************************************************************
$sessionPath = session_save_path($paths->appPath . '/temp/sessions');

// ************************************************************************************************
// HTTP Header
// ************************************************************************************************
// SecurityHeaders::apply();

// ************************************************************************************************
// Monolog
// ************************************************************************************************
$dateFormat = 'Y-m-d H:i:s';
$output = "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n";
$formatter = new LineFormatter($output, $dateFormat, true, true);
$handler = new StreamHandler($paths->logsPath . '/app.log', Level::Debug);
$handler->setFormatter($formatter);
$log = new Logger('app');
$log->pushHandler($handler);

// ************************************************************************************************
// Config Initialization
// ************************************************************************************************
try {
    $configLoader = new EnvConfigLoader();
    $appConfig = $configLoader->load([
        'config.env',
        'db.config.env',
        'mail.config.env',
    ], $paths->appPath . '/config');

    $validationResult = $configLoader->validateConfig($appConfig);
    if (!$validationResult['is_valid']) {
        foreach ($validationResult['critical_errors'] as $error) {
            $log->critical("[{$error['identifier']}] {$error['message']}");
        }

        exit('Critical configuration errors!');
    }
} catch (Exception $e) {
    $log->critical('Application Initialization failed! ' . $e->getMessage());

    exit('Application initialization failed!');
}

// ************************************************************************************************
// Global Exception Handler
// ************************************************************************************************
$exceptionHandler = new ExceptionHandler($log, $appConfig->template->debugEnabled);
// $exceptionHandler->register();

// ************************************************************************************************
// Initialize App & Authentication
// ************************************************************************************************
$environment = $appConfig->environment->env;
$usersFilePath = $paths->appPath . '/config/' . $environment . '/users.json';
$auth = new Auth($log, $usersFilePath);
$app = new App($auth, $paths);

$appDir = !empty($appConfig->environment->dir) ? DIRECTORY_SEPARATOR . $appConfig->environment->dir : '';
$app->setBaseUrl($appConfig->environment->domain);

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

foreach ($validationResult['warnings'] ?? [] as $warning) {
    $notificationManager->addConfigWarning($warning['identifier'], $warning['message']);
}

$notificationManager->cleanupResolvedConfigWarnings($validationResult['warnings'] ?? []);
$notificationManager->cleanupOldNotifications(30); // delete old notifications (> 30 Days)

// ************************************************************************************************
// Template Engine (Twig)
// ************************************************************************************************
$templateDir = $paths->appPath . '/templates';
$templateCacheDir = $paths->appPath . '/temp/templates_cache';

$loader = new FilesystemLoader($templateDir);
$twig = new Environment($loader, [
    'cache' => $appConfig->template->debugEnabled ? $templateCacheDir : false,
    'debug' => $appConfig->template->debugEnabled,
    'auto_reload' => $appConfig->template->debugEnabled,
]);

// ************************************************************************************************
// Initialize Mailer
// ************************************************************************************************
$transport = Transport::fromDsn($appConfig->mail->dsn);
$mailer = new Mailer($transport);

$mailCfg = [
    'from_email' => $appConfig->mail->fromEmail,
    'from_name' => $appConfig->mail->fromName,
    'recipients' => $appConfig->mail->alertRecipients,
];

// ************************************************************************************************
// Core Infrastructure
// ************************************************************************************************

// --- Repositories ---
$configSyncRepository = new ConfigSyncRepository($db);
$queueRepository = new AlertQueueRepository($db);

// --- Settings Sync ---
$settingsSync = new SettingsDatabaseSync($db, $log, $configSyncRepository);

// --- Tools & Resolvers ---
$tools = new Tools($paths->appPath);
$idResolver = new IdResolver($configSyncRepository);

// --- Event & Alert Infrastructure ---
$eventRecorder = new EventRecorder($db, $idResolver);
$lockManager = new ChannelLockManager($paths->tempPath . '/alert_locks', $log);
$limitsLoader = new AlertLimitsLoader($log);
$limitsConfig = $limitsLoader->load($paths->appPath . '/config/limits.json');

// --- Alert Manager ---
$alertManager = new AlertManager($queueRepository, $limitsConfig, $lockManager, $log);

// ************************************************************************************************
// Monitoring Configuration
// ************************************************************************************************
$monitoringSettingsLoader = new MonitoringSettingsLoader($eventRecorder, $alertManager, $db, $settingsSync);

try {
    $configPath = $paths->appPath . '/config';
    $log->info("Loading monitoring configuration for environment: {$environment}");
    $monitoringSettings = $monitoringSettingsLoader->loadAllConfigs($configPath, $environment);
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
// CacheManager, EventManager & Endpoints
// ************************************************************************************************
$cacheManager = new CacheManager($log, $paths->tempPath . '/api_cache', $monitoringSettings['ttl']);
$eventManager = new EventManager(
    $eventRecorder,
    $alertManager,
    $appConfig,
    $monitoringSettings['thresholds'],
    $monitoringSettings['intervals'] ?? []
);
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
    $cacheManager,
    $endpoints,
    $menuBuilder,
    $monitoringSettings,
);

$modalController = new ModalController(
    $app,
    $tools,
    $twig,
    $log,
    $appConfig,
    $cacheManager,
    $monitoringSettings['servers'],
);

$metricsQueryFactory = new ServerMetricsQueryFactory($environment, $db, $idResolver);
$metricsQuery = $metricsQueryFactory->create();
$metricsViewBuilder = new MetricsViewBuilder($metricsQuery, $log);
$metricsRenderer = new MetricsRenderer($twig, $log, $tools, $app);
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
    $cacheManager,
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
    $modalController,
    $metricsController,
    $endpoints
);

$routerSetup->setup();
$routerSetup->run();
