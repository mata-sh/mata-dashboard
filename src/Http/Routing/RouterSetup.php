<?php

declare(strict_types=1);

namespace Mata\Http\Routing;

use Bramus\Router\Router;
use InvalidArgumentException;
use Mata\Controllers\DashboardController;
use Mata\Controllers\LoginController;
use Mata\Controllers\MetricsController;
use Mata\Controllers\ModalController;
use Mata\Core\App;
use Mata\Http\Responses\Response;

/**
 * Centralizes routing configuration including authentication, rate limiting, and error handling.
 */
class RouterSetup
{
    private Router $router;
    private App $app;
    private LoginController $loginController;
    private DashboardController $dashboardController;
    private ModalController $modalController;
    private MetricsController $metricsController;
    private Endpoints $endpoints;
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
        ModalController $modalController,
        MetricsController $metricsController,
        Endpoints $endpoints
    ) {
        $this->router = $router;
        $this->app = $app;
        $this->loginController = $loginController;
        $this->dashboardController = $dashboardController;
        $this->modalController = $modalController;
        $this->metricsController = $metricsController;
        $this->endpoints = $endpoints;
    }

    /**
     * Configure all routes and middleware.
     */
    public function setup(): void
    {
        $this->setupHealthCheck();
        $this->setupRateLimiting();
        $this->setupIpWhitelist();
        $this->setupParameterValidation();
        $this->setupLoginRoutes();
        $this->setupWatchAuth();
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
        $this->router->before('GET|POST', '/login', function () {
            if ($this->app->rateLimiter->isRateLimited('login')) {
                http_response_code(429);

                exit('Too many login attempts. Please try again later.');
            }
        });

        $this->router->before('GET', '/logout', function () {
            if ($this->app->rateLimiter->isRateLimited('login')) {
                http_response_code(429);

                exit('Too many requests.');
            }
        });

        // Dashboard and watch routes - moderate limits
        $this->router->before('GET|POST', '/watch/.*', function () {
            if ($this->app->rateLimiter->isRateLimited('dashboard')) {
                http_response_code(429);

                exit('Too many requests. Please slow down.');
            }
        });

        // API routes - higher limits for data-heavy operations
        // Covers both /api/* (events, alarms, libraries, errorlog) and /api/metrics/*
        $this->router->before('GET|POST', '/api/.*', function () {
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
     * Apply IP whitelisting to login routes.
     */
    private function setupIpWhitelist(): void
    {
        $this->router->before('GET|POST', '/login', function () {
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
     * Apply authentication to all /watch/* routes.
     */
    private function setupWatchAuth(): void
    {
        $this->router->before('GET|POST', '/watch/.*', function () {
            if (!$this->app->auth->check()) {
                $this->app->redirect('login');
            }
        });

        $this->router->before('GET|POST', '/api/metrics/.*', function () {
            if (!$this->app->auth->check()) {
                $this->app->redirect('login');
            }
        });
    }

    // /**
    //  * Create middleware function that redirects unauthenticated users to login
    //  */
    protected function createAuthMiddleware(): callable
    {
        return function () {
            if (!$this->app->auth->check()) {
                $this->app->redirect('login');
            }
        };
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
        $this->router->get('/logout', function () {
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
     * - Cronjob tracking
     * - Event/alarm management
     * - Modal content endpoints
     */
    private function setupWatchRoutes(): void
    {
        $this->mount('/watch', function () {

            $dashboardRoutes = [
                ['watch', '/', 'get', fn () => $this->dashboardController->index()],
                ['servers_render', '/servers', 'get', fn () => $this->dashboardController->renderServersOverview()],
                ['cronjobs_render', '/cronjobs', 'get', fn () => $this->dashboardController->renderCronjobsOverview()],
                ['events_render', '/events', 'get', fn () => $this->dashboardController->renderEventsOverview()],
                ['alarms_render', '/alarms', 'get', fn () => $this->dashboardController->renderAlarmsOverview()],
                ['libraries_render', '/libraries', 'get', fn () => $this->dashboardController->renderLibrariesOverview()],
                ['databases_render', '/databases', 'get', fn () => $this->dashboardController->renderDatabasesOverview()],
                ['settings_render', '/settings', 'get', fn () => $this->dashboardController->renderSettingsOverview()],
                ['database_notice_ack', '/database-notice/ack', 'post', fn () => $this->dashboardController->acknowledgeDatabaseNotice()],
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

            // Content-based modals handled by ModalController
            $contentModalRoutes = [
                ['modal_app_readme_render', '/modal/{server}/{app}/readme', 'get', fn ($server, $app) => $this->modalController->renderAppReadme($server, $app)],
                ['modal_app_readme_refresh', '/modal/{server}/{app}/readme/refresh', 'get', fn ($server, $app) => $this->modalController->renderAppReadme($server, $app, forceRefresh: true)],
                ['modal_app_errorlog_render', '/modal/{server}/{app}/errorlog', 'get', fn ($server, $app) => $this->modalController->renderAppErrorlog($server, $app)],
                ['modal_app_errorlog_refresh', '/modal/{server}/{app}/errorlog/refresh', 'get', fn ($server, $app) => $this->modalController->renderAppErrorlog($server, $app, forceRefresh: true)],
                ['modal_server_info_render', '/modal/{server}/info', 'get', fn ($server) => $this->modalController->renderServerInfo($server)],
                ['modal_server_info_refresh', '/modal/{server}/info/refresh', 'get', fn ($server) => $this->modalController->renderServerInfo($server, forceRefresh: true)],
            ];

            // Metrics-based modals with time-series charts handled by MetricsController
            $metricsModalRoutes = [
                ['modal_server_process_count_render', '/modal/{server}/process-count', 'get', fn ($server) => $this->metricsController->renderProcessCountModal($server)],
                // Future metrics modals (CPU trends, RAM history, etc.) will be added here
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
                ['events_json', '/events', 'get', fn () => $this->dashboardController->getEventsJson()],
                ['alarms_json', '/alarms', 'get', fn () => $this->dashboardController->getAlarmsJson()],
                ['libraries_json', '/libraries', 'get', fn () => $this->dashboardController->getLibrariesJson()],
                ['app_errorlog_json', '/errorlog/{server}/{app}', 'get', fn ($server, $app) => $this->modalController->getAppErrorlogJson($server, $app, forceRefresh: true)],
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
                ['metrics_server', '/{server}/{metric}', 'get', fn ($server, $metric) => $this->metricsController->getServerMetricSeries($server, $metric)],
                ['metrics_server_multi', '/{server}/compare', 'get', fn ($server) => $this->metricsController->getServerMultiMetricSeries($server)],
                ['metrics_dashboard_data', '/dashboard', 'get', fn () => $this->metricsController->getDashboardMetricsData()],
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
        // Validate server and app parameters for modal routes
        $this->router->before('GET', '/watch/modal/([^/]+)/([^/]+)/.*', function ($server, $app) {
            // Validate server parameter using filter_var
            if (
                !filter_var($server, FILTER_VALIDATE_REGEXP, [
                    'options' => ['regexp' => '/^[a-zA-Z0-9_\-\.]+$/'],
                ])
            ) {
                throw new InvalidArgumentException('Invalid server parameter');
            }

            // Validate app parameter using filter_var
            if (
                !filter_var($app, FILTER_VALIDATE_REGEXP, [
                    'options' => ['regexp' => '/^[a-zA-Z0-9_\-\.]+$/'],
                ])
            ) {
                throw new InvalidArgumentException('Invalid app parameter');
            }
        });

        // Validate server parameter for server-only modal routes
        $this->router->before('GET', '/watch/modal/([^/]+)/info.*', function ($server) {
            // Validate server parameter using filter_var
            if (
                !filter_var($server, FILTER_VALIDATE_REGEXP, [
                    'options' => ['regexp' => '/^[a-zA-Z0-9_\-\.]+$/'],
                ])
            ) {
                throw new InvalidArgumentException('Invalid server parameter');
            }
        });

        // Validate process count modal route parameters
        $this->router->before('GET', '/watch/modal/([^/]+)/process-count', function ($server) {
            if (
                !filter_var($server, FILTER_VALIDATE_REGEXP, [
                    'options' => ['regexp' => '/^[a-zA-Z0-9_\-\.]+$/'],
                ])
            ) {
                throw new InvalidArgumentException('Invalid server parameter');
            }
        });

        // Validate metrics JSON route parameters
        $this->router->before('GET', '/api/metrics/([^/]+)/([^/]+)', function ($server, $metric) {
            $serverValid = filter_var($server, FILTER_VALIDATE_REGEXP, [
                'options' => ['regexp' => '/^[a-zA-Z0-9_\-\.]+$/'],
            ]);

            $metricValid = filter_var($metric, FILTER_VALIDATE_REGEXP, [
                'options' => ['regexp' => '/^[a-zA-Z0-9_\-\.]+$/'],
            ]);

            if ($serverValid === false || $metricValid === false) {
                throw new InvalidArgumentException('Invalid metrics route parameter');
            }
        });

        // Validate multi-metric comparison route parameters
        $this->router->before('GET', '/api/metrics/([^/]+)/compare', function ($server) {
            if (
                !filter_var($server, FILTER_VALIDATE_REGEXP, [
                    'options' => ['regexp' => '/^[a-zA-Z0-9_\-\.]+$/'],
                ])
            ) {
                throw new InvalidArgumentException('Invalid server parameter');
            }
        });

        // Validate errorlog API route parameters
        $this->router->before('GET', '/api/errorlog/([^/]+)/([^/]+)', function ($server, $app) {
            $serverValid = filter_var($server, FILTER_VALIDATE_REGEXP, [
                'options' => ['regexp' => '/^[a-zA-Z0-9_\-\.]+$/'],
            ]);

            $appValid = filter_var($app, FILTER_VALIDATE_REGEXP, [
                'options' => ['regexp' => '/^[a-zA-Z0-9_\-\.]+$/'],
            ]);

            if ($serverValid === false || $appValid === false) {
                throw new InvalidArgumentException('Invalid errorlog API route parameter');
            }
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
