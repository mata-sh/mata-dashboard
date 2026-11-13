<?php

declare(strict_types=1);

namespace Mata\Bootstrap;

use Mata\Config\Dto\ApplicationConfig;
use Mata\Config\EnvConfigFatalException;
use Mata\Config\EnvConfigLoader;
use Mata\Config\EnvConfigManager;
use Mata\Config\LogConfigurator;
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
use Mata\Core\App;
use Mata\Core\ExceptionHandler;
use Mata\Core\Session;
use Mata\Database\DatabaseInterface;
use Mata\Http\Request;
use Mata\Http\Security\CsrfGuard;
use Mata\Http\Security\RateLimiting\RequestRateLimiterFactory;
use Mata\Http\Security\SecurityHeaders;
use Mata\Models\Auth\UserRepository;
use Mata\Services\Auth\UserAuthenticator;
use Mata\Services\Navigation\MenuBuilder;
use Mata\Services\Notification\SystemNotificationManager;
use Mata\Services\ServerMetrics\MetricCatalog;
use Mata\Services\ServerMetrics\MetricQueryPolicy;
use Mata\Utils\PathResolver;
use Mata\Utils\Tools;
use Mata\Utils\UsersFilePathResolver;
use Mata\Views\Support\UiTheme;
use Monolog\ErrorHandler as MonologErrorHandler;
use Psr\Log\LoggerInterface;
use Throwable;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Initializes the shared runtime dependencies of a web request: configuration, logging,
 * templates, session, authentication, database and system notifications.
 *
 * Configuration and authentication failures render an error page and terminate the request;
 * everything after that reports through {@see ExceptionHandler}. The monitoring graph comes
 * from {@see monitoring()}.
 */
final readonly class DashboardRuntime
{
    /**
     * @param array<int,array{type:string,message:string}> $validationWarnings
     */
    public function __construct(
        public PathResolver $paths,
        public LoggerInterface $log,
        public Request $request,
        public Environment $twig,
        public Tools $tools,
        public ApplicationConfig $appConfig,
        public array $validationWarnings,
        public UserAuthenticator $auth,
        public App $app,
        public CsrfGuard $csrf,
        public DatabaseInterface $db,
        public SystemNotificationManager $notifications,
    ) {}

    /**
     * Boot the runtime for the web entry point.
     *
     * @param string $entryPointFile `__FILE__` of public/index.php
     */
    public static function boot(string $entryPointFile): self
    {
        $request = Request::fromGlobals();
        $paths = PathResolver::fromEntryPoint($entryPointFile);
        $tools = new Tools($paths->appPath);

        // Until the configuration is loaded, PHP errors go to boot.log and are shown.
        ini_set('display_errors', 1);
        ini_set('display_startup_errors', 0);
        ini_set('log_errors', 1);
        ini_set('error_log', $paths->bootLogPath);

        $logConfigurator = new LogConfigurator();
        $log = $logConfigurator->createBootLogger($paths);

        $twig = new Environment(new FilesystemLoader($paths->templatesPath), ['autoescape' => 'html']);
        $twig->addGlobal('theme', UiTheme::fromCookie($request->cookie(UiTheme::COOKIE))->value);

        // Configuration failures occur before App initialization; derive the public asset path from the request.
        $errorAssetBaseUrl = rtrim($request->scriptDirectory(), '/') . '/assets';

        try {
            $initResult = (new EnvConfigManager(
                configDir: $paths->configPath,
                exampleDir: $paths->configExamplePath,
                requiredFiles: EnvConfigLoader::INSTANCE_FILES,
                logger: $log
            ))->initialize();
        } catch (EnvConfigFatalException $e) {
            $log->critical($e->getMessage(), ['context' => $e->getContext()]);

            self::renderErrorPage($twig, 'partials/errors/config-error.html.twig', $errorAssetBaseUrl, [
                'title' => $e->getTitle(),
                'heading' => $e->getHeading(),
                'type' => $e->getType(),
                'message' => $e->getUserMessage(),
                'context' => $e->getContext(),
                'documentation_url' => $e->getDocumentationUrl(),
            ]);
        } catch (Throwable $e) {
            $log->critical('Application Initialization failed! ' . $e->getMessage(), ['exception' => $e]);

            exit('Application initialization failed!');
        }

        $appConfig = $initResult->config;
        $request = $request->withTrustedProxies($appConfig->general->trustedProxies);

        SecurityHeaders::apply(
            secure: $request->isSecure(),
            enabled: $appConfig->general->securityHeadersEnabled,
            hstsEnabled: $appConfig->general->hstsEnabled,
        );

        $paths = $paths->withOverrides(
            logFile: $appConfig->logging->filePath,
            tempDir: $appConfig->environment->tempDir,
        );
        $paths->ensureLogsDirectory();
        $paths->ensureTempDirectory();

        $log = $logConfigurator->createConfiguredLogger($appConfig->logging, $paths, 'app');
        ini_set('error_log', $paths->logsPath . '/php-error.log');
        ini_set('display_errors', 0);

        (new ExceptionHandler($log, $request, $appConfig->template->debugEnabled))->register();
        (new MonologErrorHandler($log))
            ->registerErrorHandler(callPrevious: true)
            ->registerFatalHandler();
        Session::start($paths->tempPath . '/sessions', $request->isSecure());

        // Seed public/branding/ with default assets on first run; instance assets placed there
        // override the defaults and are gitignored (see public/branding/README.md).
        $brandingAssets = (new BrandingResolver(
            $paths->appPath . '/public/branding',
            $paths->appPath . '/public/assets/branding',
            $log,
        ))->resolve();
        $twig->addGlobal('branding', ['app_name' => $appConfig->general->appName] + $brandingAssets);

        $twig->setCache($appConfig->template->debugEnabled ? $paths->tempPath . '/templates-cache' : false);
        if ($appConfig->template->debugEnabled) {
            $twig->enableDebug();
            $twig->enableAutoReload();
        }

        $usersFilePath = null;

        try {
            $usersFilePath = UsersFilePathResolver::resolve($paths);
            $auth = new UserAuthenticator(UserRepository::open($usersFilePath), $log, $request->isSecure());
            $auth->resumeFromRememberCookie($request->cookie(UserAuthenticator::REMEMBER_COOKIE));
        } catch (Throwable $e) {
            $log->critical('Authentication initialization failed: ' . $e->getMessage());

            self::renderErrorPage($twig, 'partials/errors/auth-error.html.twig', $errorAssetBaseUrl, [
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
                'documentation_url' => 'https://docs.mata.sh/guides/user-management/#persistent-authentication-data',
            ]);
        }

        $requestRateLimiter = RequestRateLimiterFactory::create($appConfig->rateLimiting, $log);
        $app = new App($auth, $appConfig, $paths, $request, $requestRateLimiter);
        $csrf = new CsrfGuard($request);
        $twig->addGlobal('csrf', $csrf);

        $db = (new DatabaseBootstrap($appConfig->database, $log))->initialize();

        $notifications = new SystemNotificationManager($db, $log, $paths);
        foreach ($initResult->warnings as $warning) {
            $notifications->addConfigWarning($warning['type'], $warning['message']);
        }

        return new self(
            paths: $paths,
            log: $log,
            request: $request,
            twig: $twig,
            tools: $tools,
            appConfig: $appConfig,
            validationWarnings: $initResult->warnings,
            auth: $auth,
            app: $app,
            csrf: $csrf,
            db: $db,
            notifications: $notifications,
        );
    }

    /**
     * Monitoring services for the web request: every config section including menu and charts,
     * a servers fallback so the dashboard still renders when servers.json is unreadable, and
     * servers.json synced to the database before server IDs are resolved.
     */
    public function monitoring(MetricCatalog $metricCatalog, MetricQueryPolicy $metricQueryPolicy): MonitoringRuntime
    {
        $configLoader = new MonitoringConfigLoader(
            servers: new ServersConfigLoader(fallbackOnFailure: true),
            ttl: new TtlConfigLoader(fallbackOnFailure: true),
            dataRetention: new DataRetentionConfigLoader(fallbackOnFailure: true),
            intervals: new IntervalsConfigLoader(fallbackOnFailure: true),
            probes: new ProbesConfigLoader(fallbackOnFailure: true),
            notificationRules: new NotificationRulesConfigLoader(fallbackOnFailure: true),
            thresholds: new ThresholdsConfigLoader(fallbackOnFailure: true),
            menu: new MenuConfigLoader(validKeys: MenuBuilder::MENU_KEYS, fallbackOnFailure: true),
            charts: new ChartsConfigLoader(
                metricCatalog: $metricCatalog,
                queryPolicy: $metricQueryPolicy,
                fallbackOnFailure: true,
            ),
        );

        return MonitoringRuntime::build(
            paths: $this->paths,
            appConfig: $this->appConfig,
            log: $this->log,
            db: $this->db,
            configLoader: $configLoader,
            syncServerConfig: true,
        );
    }

    /**
     * @param array<string,mixed> $error
     */
    private static function renderErrorPage(Environment $twig, string $template, string $assetBaseUrl, array $error): never
    {
        http_response_code(500);
        echo $twig->render($template, [
            'asset_base_url' => $assetBaseUrl,
            'error' => $error,
        ]);

        exit(1);
    }
}
