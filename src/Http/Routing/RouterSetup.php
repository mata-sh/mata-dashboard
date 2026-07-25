<?php

declare(strict_types=1);

namespace Mata\Http\Routing;

use Bramus\Router\Router;
use Mata\Controllers\AuditController;
use Mata\Controllers\DashboardController;
use Mata\Controllers\LoginController;
use Mata\Controllers\MetricsController;
use Mata\Controllers\ModalController;
use Mata\Core\App;
use Mata\Http\Responses\Response;
use Mata\Http\Responses\ResponseFactory;
use Mata\Http\Security\CsrfGuard;
use Mata\Http\Security\RouteParameter;

/**
 * Centralizes routing configuration including authentication, rate limiting, and error handling.
 */
class RouterSetup
{
    /**
     * Bramus Router stores before middleware by explicit request method; `*` is not a wildcard.
     */
    private const ALL_REQUEST_METHODS = 'GET|POST|PUT|PATCH|DELETE|OPTIONS';

    private Router $router;
    private App $app;
    private LoginController $loginController;
    private DashboardController $dashboardController;
    private AuditController $auditController;
    private ModalController $modalController;
    private MetricsController $metricsController;
    private Endpoints $endpoints;
    private CsrfGuard $csrf;
    private string $baseRoute;

    /**
     * Initialize router setup with required dependencies.
     *
     * @param App $app Application container for accessing services
     */
    public function __construct(
        Router $router,
        App $app,
        LoginController $loginController,
        DashboardController $dashboardController,
        AuditController $auditController,
        ModalController $modalController,
        MetricsController $metricsController,
        Endpoints $endpoints,
        CsrfGuard $csrf
    ) {
        $this->router = $router;
        $this->app = $app;
        $this->loginController = $loginController;
        $this->dashboardController = $dashboardController;
        $this->auditController = $auditController;
        $this->modalController = $modalController;
        $this->metricsController = $metricsController;
        $this->endpoints = $endpoints;
        $this->csrf = $csrf;
    }

    /**
     * Configure all routes and middleware.
     */
    public function setup(): void
    {
        $this->setupHealthCheck();
        $this->setupRateLimiting();
        $this->setupIpWhitelist();
        $this->setupLoginRoutes();
        $this->setupProtectedRouteAuth();
        $this->setupCsrfProtection();
        $this->setupParameterValidation();
        $this->setupWatchRoutes();
        $this->setupApiRoutes();
        $this->setupMetricsRoutes();
        $this->setup404Handler();
    }

    /**
     * Configure health check endpoint for container monitoring.
     * This endpoint is unauthenticated and excluded from rate limiting.
     */
    private function setupHealthCheck(): void
    {
        $this->endpoints->register('health', '/health');
        $this->router->get('/health', function () {
            http_response_code(200);
            header('Content-Type: application/json');
            echo json_encode([
                'status' => 'healthy',
                'timestamp' => time(),
            ]);

            exit;
        });
    }

    /**
     * Apply rate limiting to route groups with different limits per group.
     */
    private function setupRateLimiting(): void
    {
        // Skip if rate limiter service not available
        if ($this->app->rateLimiter === null) {
            return;
        }

        // Login routes - strictest limits (brute force protection)
        $this->router->before(self::ALL_REQUEST_METHODS, '/login', function () {
            if ($this->app->rateLimiter->isRateLimited('login')) {
                http_response_code(429);

                exit('Too many login attempts. Please try again later.');
            }
        });

        $this->router->before(self::ALL_REQUEST_METHODS, '/logout', function () {
            if ($this->app->rateLimiter->isRateLimited('login')) {
                http_response_code(429);

                exit('Too many requests.');
            }
        });

        // Dashboard and watch routes - moderate limits
        $this->router->before(self::ALL_REQUEST_METHODS, '/watch(?:/.*)?', function () {
            if ($this->app->rateLimiter->isRateLimited('dashboard')) {
                http_response_code(429);

                exit('Too many requests. Please slow down.');
            }
        });

        // API routes - /api/* (events, alarms, libraries, logs) and /api/metrics/*
        $this->router->before(self::ALL_REQUEST_METHODS, '/api/.*', function () {
            if ($this->app->rateLimiter->isRateLimited('api')) {
                http_response_code(429);
                header('Content-Type: application/json');
                echo json_encode([
                    'error' => 'Rate limit exceeded',
                    'message' => 'Too many API requests. Please try again later.',
                ]);

                exit;
            }
        });
    }

    /**
     * Apply IP whitelisting to every route except the health check.
     */
    private function setupIpWhitelist(): void
    {
        $this->router->before(self::ALL_REQUEST_METHODS, '/(?!health$).*', function () {
            $config = $this->app->config->general;

            if (!$config->ipWhitelistEnabled) {
                return; // Feature disabled
            }

            $clientIp = $_SERVER['REMOTE_ADDR'];

            if (!in_array($clientIp, $config->allowedIps, true)) {
                error_log("SECURITY: Blocked IP access to login: {$clientIp}");
                http_response_code(403);

                exit('Access forbidden');
            }
        });
    }

    /**
     * Apply CSRF protection to unsafe HTTP methods.
     */
    private function setupCsrfProtection(): void
    {
        $this->router->before('POST|PUT|PATCH|DELETE', '/.*', function () {
            if ($this->csrf->validateRequest()) {
                return;
            }
            if (ResponseFactory::isHtmxRequest()) {
                ResponseFactory::forbidden('Invalid or missing CSRF token')
                    ->addHeader('HX-Refresh', 'true')
                    ->send();

                exit;
            }
            if (!ResponseFactory::expectsJson()) {
                $this->app->redirect('login');
            }
            ResponseFactory::forbidden('Invalid or missing CSRF token')->send();

            exit;
        });
    }

    /**
     * Apply authentication to protected dashboard and API routes.
     */
    private function setupProtectedRouteAuth(): void
    {
        $this->router->before(self::ALL_REQUEST_METHODS, '/watch(?:/.*)?', function () {
            if ($this->app->auth->check()) {
                return;
            }

            if (ResponseFactory::isHtmxRequest()) {
                $loginPath = rtrim($this->app->getBasePath(), '/') . '/login';
                ResponseFactory::htmxRedirect($loginPath)->send();

                exit;
            }

            $this->app->redirect('login');
        });

        $this->router->before(self::ALL_REQUEST_METHODS, '/api/.*', function () {
            if ($this->app->auth->check()) {
                return;
            }

            ResponseFactory::unauthorized('Authentication required')->send();

            exit;
        });
    }

    /**
     * Configure login/logout routes and authentication flow.
     */
    private function setupLoginRoutes(): void
    {
        // login (GET)
        $this->endpoints->register('login', '/login');
        $this->router->get('/login', function () {
            $this->loginController->show();
        });

        // login submit (POST)
        $this->endpoints->register('loginSubmit', '/login');
        $this->router->post('/login', function () {
            $this->loginController->submit();
        });

        // logout
        $this->endpoints->register('logout', '/logout');
        $this->router->post('/logout', function () {
            $this->loginController->logout();
        });
    }

    /**
     * Register a route with Bramus Router and endpoint registry.
     */
    private function registerRoute(string $name, string $path, string $method, callable $handler): void
    {
        $this->endpoints->register($name, ltrim($this->baseRoute, '/') . $path);
        $this->router->{$method}($path, function (...$args) use ($handler) {
            $result = $handler(...$args);

            if ($result instanceof Response) {
                $result->send();

                return null;
            }

            return $result;
        });
    }

    /**
     * Configure watch dashboard routes including:
     * - Server monitoring
     * - Event/alarm management
     * - Modal content endpoints
     */
    private function setupWatchRoutes(): void
    {
        $this->mount('/watch', function () {

            $dashboardRoutes = [
                ['watch', '/', 'get', fn () => $this->dashboardController->index()],
                ['servers_render', '/servers', 'get', fn () => $this->dashboardController->renderServersOverview()],
                ['events_render', '/events', 'get', fn () => $this->dashboardController->renderEventsOverview()],
                ['alarms_render', '/alarms', 'get', fn () => $this->dashboardController->renderAlarmsOverview()],
                ['libraries_render', '/libraries', 'get', fn () => $this->dashboardController->renderLibrariesOverview()],
                ['databases_render', '/databases', 'get', fn () => $this->dashboardController->renderDatabasesOverview()],
                ['settings_render', '/settings', 'get', fn () => $this->dashboardController->renderSettingsOverview()],
                ['notification_bell_render', '/notification-bell', 'get', fn () => $this->dashboardController->renderNotificationBell()],
                ['notification_bell_ack_all', '/notification-bell/ack-all', 'post', fn () => $this->dashboardController->acknowledgeAllAlarms()],
            ];

            $refreshRoutes = [
                ['refresh_servers', '/refresh/servers', 'get', fn () => $this->dashboardController->renderServersOverview(forceRefresh: true)],
                ['refresh_server', '/refresh/server/{server}', 'get', fn ($server) => $this->dashboardController->refreshServer(server: $server, forceRefresh: true)],
                ['refresh_serverstats', '/refresh/serverstats/{server}', 'get', fn ($server) => $this->dashboardController->refreshServerStats(server: $server, forceRefresh: true)],
                ['refresh_databases', '/refresh/databases', 'get', fn () => $this->dashboardController->renderDatabasesOverview(forceRefresh: true)],
            ];

            $metricsRoutes = [
                ['metrics_render', '/metrics', 'get', fn () => $this->metricsController->renderMetricsOverview()],
            ];

            $auditRoutes = [
                ['audit_section_render', '/audit/section', 'get', fn () => $this->auditController->renderAuditSection()],
                ['audit_run_render', '/audit/run', 'post', fn () => $this->auditController->runAudit()],
                ['audit_run_app_render', '/audit/run/{server}/{app}', 'get', fn ($server, $app) => $this->auditController->runAuditApp($server, $app)],
            ];

            // Content-based modals handled by ModalController
            $contentModalRoutes = [
                ['modal_app_readme_render', '/modal/{server}/{app}/readme', 'get', fn ($server, $app) => $this->modalController->renderAppReadme($server, $app)],
                ['modal_app_readme_refresh', '/modal/{server}/{app}/readme/refresh', 'get', fn ($server, $app) => $this->modalController->renderAppReadme($server, $app, forceRefresh: true)],
                ['modal_app_logs_render', '/modal/{server}/{app}/logs', 'get', fn ($server, $app) => $this->modalController->renderAppLogs($server, $app)],
                ['modal_app_logs_refresh', '/modal/{server}/{app}/logs/refresh', 'get', fn ($server, $app) => $this->modalController->renderAppLogs($server, $app, forceRefresh: true)],
                ['modal_app_log_files_render', '/modal/{server}/{app}/log-files', 'get', fn ($server, $app) => $this->modalController->renderAppLogFiles($server, $app)],
                ['modal_app_log_files_refresh', '/modal/{server}/{app}/log-files/refresh', 'get', fn ($server, $app) => $this->modalController->renderAppLogFiles($server, $app, forceRefresh: true)],
                ['modal_db_processes_render', '/modal/{server}/database/{database}/processes', 'get', fn ($server, $database) => $this->modalController->renderDatabaseProcesses($server, $database)],
                ['modal_db_processes_refresh', '/modal/{server}/database/{database}/processes/refresh', 'get', fn ($server, $database) => $this->modalController->renderDatabaseProcesses($server, $database, forceRefresh: true)],
                ['modal_server_info_render', '/modal/{server}/info', 'get', fn ($server) => $this->modalController->renderServerInfo($server)],
                ['modal_server_info_refresh', '/modal/{server}/info/refresh', 'get', fn ($server) => $this->modalController->renderServerInfo($server, forceRefresh: true)],
                ['modal_server_cronjobs_render', '/modal/{server}/cronjobs', 'get', fn ($server) => $this->modalController->renderServerCronjobs($server)],
                ['modal_server_cronjobs_refresh', '/modal/{server}/cronjobs/refresh', 'get', fn ($server) => $this->modalController->renderServerCronjobs($server, forceRefresh: true)],
            ];

            // Metrics-based modals with time-series charts handled by MetricsController
            $metricsModalRoutes = [
                ['modal_server_metrics_render', '/modal/{server}/metrics', 'get', fn ($server) => $this->metricsController->renderServerMetricsModal($server)],
                ['modal_server_process_count_render', '/modal/{server}/process-count', 'get', fn ($server) => $this->metricsController->renderProcessCountModal($server)],
            ];

            // Register routes
            foreach ($refreshRoutes as [$name, $path, $method, $handler]) {
                $this->registerRoute($name, $path, $method, $handler);
            }

            foreach ($dashboardRoutes as [$name, $path, $method, $handler]) {
                $this->registerRoute($name, $path, $method, $handler);
            }

            foreach ($metricsRoutes as [$name, $path, $method, $handler]) {
                $this->registerRoute($name, $path, $method, $handler);
            }

            foreach ($auditRoutes as [$name, $path, $method, $handler]) {
                $this->registerRoute($name, $path, $method, $handler);
            }

            foreach ($contentModalRoutes as [$name, $path, $method, $handler]) {
                $this->registerRoute($name, $path, $method, $handler);
            }

            foreach ($metricsModalRoutes as [$name, $path, $method, $handler]) {
                $this->registerRoute($name, $path, $method, $handler);
            }
        });
    }

    /**
     * Configure API routes for JSON data endpoints (/api/*).
     * All endpoints return JSON responses.
     */
    private function setupApiRoutes(): void
    {
        $this->mount('/api', function () {
            $apiRoutes = [
                // Data reads (JSON twins of the /watch pages)
                ['events_json', '/events', 'get', fn () => $this->dashboardController->getEventsJson()],
                ['alarms_json', '/alarms', 'get', fn () => $this->dashboardController->getAlarmsJson()],
                ['libraries_json', '/libraries', 'get', fn () => $this->dashboardController->getLibrariesJson()],
                ['app_logs_json', '/logs/{server}/{app}', 'get', fn ($server, $app) => $this->modalController->getAppLogsJson($server, $app)],
                ['app_logs_status_json', '/logs-status/{server}/{app}', 'get', fn ($server, $app) => $this->modalController->getAppLogsStatusJson($server, $app, forceRefresh: true)],

                // Mutations
                ['alarms_ack', '/alarms/{alarmId}/ack', 'post', fn ($alarmId) => $this->dashboardController->acknowledgeAlarm($alarmId)],
                ['database_notice_ack', '/database-notice/ack', 'post', fn () => $this->dashboardController->acknowledgeDatabaseNotice()],
            ];

            foreach ($apiRoutes as [$name, $path, $method, $handler]) {
                $this->registerRoute($name, $path, $method, $handler);
            }
        });
    }

    /**
     * Configure metrics API routes for JSON data endpoints (/api/metrics/*).
     * All endpoints return JSON time-series and aggregated metrics data.
     */
    private function setupMetricsRoutes(): void
    {
        $this->mount('/api/metrics', function () {
            $metricsApiRoutes = [
                ['metrics_dashboard_data', '/dashboard', 'get', fn () => $this->metricsController->getDashboardMetricsData()],
                ['metrics_server_multi', '/{server}/compare', 'get', fn ($server) => $this->metricsController->getServerMultiMetricSeries($server)],
                ['metrics_server', '/{server}/{metric}', 'get', fn ($server, $metric) => $this->metricsController->getServerMetricSeries($server, $metric)],
            ];

            foreach ($metricsApiRoutes as [$name, $path, $method, $handler]) {
                $this->registerRoute($name, $path, $method, $handler);
            }
        });
    }

    private function mount(string $baseRoute, callable $callback): void
    {
        // Use this property in registerRoute() for absolute paths in endpoints
        $this->baseRoute = $baseRoute;
        $this->router->mount($baseRoute, function () use ($callback) {
            $callback();
        });
    }

    /**
     * Set up 404 handler to redirect to watch dashboard.
     */
    private function setup404Handler(): void
    {
        $this->router->set404(function () {
            $this->app->redirect('watch');
        });
    }

    /**
     * Set up parameter validation for routes with parameters.
     */
    private function setupParameterValidation(): void
    {
        // Validate server and database parameters for database process-list modal routes
        $this->router->before('GET', '/watch/modal/([^/]+)/database/([^/]+)/processes.*', function ($server, $database) {
            RouteParameter::assertAllSafe([$server, $database], 'Invalid database process route parameter');
        });

        // Validate server and app parameters for modal routes
        $this->router->before('GET', '/watch/modal/([^/]+)/([^/]+)/.*', function ($server, $app) {
            RouteParameter::assertSafe($server, 'Invalid server parameter');
            RouteParameter::assertSafe($app, 'Invalid app parameter');
        });

        // Validate server parameter for server-only modal routes
        $this->router->before('GET', '/watch/modal/([^/]+)/info.*', function ($server) {
            RouteParameter::assertSafe($server, 'Invalid server parameter');
        });

        // Validate Composer audit route parameters
        $this->router->before('GET', '/watch/audit/run/([^/]+)/([^/]+)', function ($server, $app) {
            RouteParameter::assertAllSafe([$server, $app], 'Invalid audit route parameter');
        });

        // Validate process count modal route parameters
        $this->router->before('GET', '/watch/modal/([^/]+)/process-count', function ($server) {
            RouteParameter::assertSafe($server, 'Invalid server parameter');
        });

        // Validate metrics JSON route parameters
        $this->router->before('GET', '/api/metrics/([^/]+)/([^/]+)', function ($server, $metric) {
            RouteParameter::assertAllSafe([$server, $metric], 'Invalid metrics route parameter');
        });

        // Validate multi-metric comparison route parameters
        $this->router->before('GET', '/api/metrics/([^/]+)/compare', function ($server) {
            RouteParameter::assertSafe($server, 'Invalid server parameter');
        });

        // Validate logs and logs-status API route parameters
        $this->router->before('GET', '/api/(logs|logs-status)/([^/]+)/([^/]+)', function ($routeType, $server, $app) {
            RouteParameter::assertAllSafe([$server, $app], 'Invalid API route parameter');
        });
    }

    /**
     * Start the router to handle incoming requests.
     */
    public function run(): void
    {
        $this->router->run();
    }
}
