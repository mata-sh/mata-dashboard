<?php

declare(strict_types=1);

namespace Mata\Views;

use Exception;
use Mata\Constants\AppAvailabilityStatus;
use Mata\Constants\NodeHealthState;
use Mata\Core\App;
use Mata\Http\Routing\Endpoints;
use Mata\Utils\Html;
use Mata\Utils\Tools;
use Monolog\Logger;
use Twig\Environment;

/**
 * Server rendering service.
 *
 * Handles server-related view rendering:
 * - Server container and card generation
 * - Server error card rendering
 * - Log and process table rendering
 * - Server metrics visualization
 */
class ServerRenderer extends BaseRenderer
{
    private LogRenderer $logRenderer;

    public function __construct(
        Environment $twig,
        Logger $logger,
        Tools $tools,
        App $app,
        Endpoints $endpoints,
        LogRenderer $logRenderer
    ) {
        parent::__construct($twig, $logger, $tools, $app, $endpoints);
        $this->logRenderer = $logRenderer;
    }

    /**
     * Render complete servers container with layout wrapper.
     *
     * @param array<int,array<string,mixed>> $serversViewData Server view data from ServerViewBuilder
     * @param array<string,mixed>            $layoutConfig    Layout configuration (server_columns, container_width)
     *
     * @return string Generated HTML for complete servers container
     */
    public function renderServersContainer(array $serversViewData, array $layoutConfig): string
    {
        $serverCardsHtml = $this->renderServerCards($serversViewData);

        return $this->twig->render('partials/containers/servers-container.html.twig', [
            'content' => [
                'server_container' => $serverCardsHtml,
            ],
            'layout' => $layoutConfig,
        ]);
    }

    /**
     * Render server cards without wrapper template.
     *
     * @param array<int,array<string,mixed>> $serversRenderData
     *
     * @return string Generated HTML for servers container
     */
    public function renderServerCards(array $serversRenderData): string
    {
        $serverContainerHtml = '';
        foreach ($serversRenderData as $serverRenderData) {
            $serverContainerHtml .= $this->renderServerCard($serverRenderData);
        }

        return $serverContainerHtml;
    }

    /**
     * Render individual server card from prepared render data.
     *
     * @param array<string,mixed> $serverRenderData
     *
     * @return string Generated HTML for server card
     */
    public function renderServerCard(array $serverRenderData): string
    {
        $serverName = (string) ($serverRenderData['server'] ?? 'unknown');
        $serverId = $this->generateHtmlId($serverName, 'server');
        $serverConfig = $serverRenderData['config'] ?? [];
        $nodeHealth = $serverRenderData['health'] ?? [];

        if (isset($serverRenderData['error'])) {
            $errorMessage = is_array($serverRenderData['error'])
                ? (string) ($serverRenderData['error']['message'] ?? 'Unknown error')
                : (string) $serverRenderData['error'];

            $data = $this->buildServerErrorCardData(
                $serverConfig,
                $serverId,
                $errorMessage,
                $serverRenderData['partial'] ?? null,
                $nodeHealth
            );

            return $this->twig->render('partials/cards/server-card.html.twig', $data);
        }

        $metrics = $serverRenderData['metrics'] ?? [];
        $apps = $serverRenderData['apps'] ?? [];
        $appsCache = $serverRenderData['apps_cache'] ?? ['age' => 0, 'ttl' => 0];

        $appsHtml = $this->renderAppCards(
            $serverName,
            $apps,
            (int) ($appsCache['age'] ?? 0),
            (int) ($appsCache['ttl'] ?? 0)
        );

        $data = $this->buildServerCardData(
            $serverName,
            $metrics,
            $serverConfig,
            $serverId,
            $appsHtml,
            $nodeHealth
        );

        return $this->twig->render('partials/cards/server-card.html.twig', $data);
    }

    /**
     * Render all application cards for a server.
     *
     * @param array<int,array<string,mixed>> $apps
     */
    private function renderAppCards(
        string $server,
        array $apps,
        int $appsCacheAge,
        int $appsCacheTtl
    ): string {
        $appsHtml = '';
        foreach ($apps as $appRenderData) {
            $appsHtml .= $this->renderSingleAppCard(
                $server,
                $appRenderData,
                $appsCacheAge,
                $appsCacheTtl
            );
        }

        return $appsHtml;
    }

    /**
     * Render a single application card from prepared data.
     *
     * @param array<string,mixed> $appRenderData
     */
    private function renderSingleAppCard(
        string $server,
        array $appRenderData,
        int $appsCacheAge,
        int $appsCacheTtl
    ): string {
        $appKey = (string) ($appRenderData['key'] ?? '');
        $appData = $appRenderData['data'] ?? [];
        $logAnalysis = $appRenderData['logAnalysis'] ?? [];
        $sessionData = $appRenderData['session'] ?? [];
        $availability = $appRenderData['availability'] ?? [];

        $data = $this->buildAppCardData(
            $appKey,
            $appData,
            $server,
            $logAnalysis,
            $sessionData,
            $appsCacheAge,
            $appsCacheTtl,
            $availability
        );

        return $this->twig->render('partials/cards/app-card.html.twig', $data);
    }

    /**
     * Render server stats.
     *
     * @return string Generated HTML for specifif servers stats
     */
    public function renderServerStats(array $serverRenderData): string
    {
        $serverName = (string) ($serverRenderData['server'] ?? 'unknown');
        $serverConfig = $serverRenderData['config'] ?? [];
        $serverId = $this->generateHtmlId($serverName, 'server');

        $metrics = $serverRenderData['metrics'] ?? [];

        $data = $this->buildServerStatsData(
            $serverName,
            $metrics,
            $serverConfig,
            $serverId,
        );

        return $this->twig->render('partials/cards/server-stats.html.twig', $data);
    }

    /**
     * Render error container with layout wrapper.
     *
     * @param string              $message      Error message to display
     * @param array<string,mixed> $layoutConfig Layout configuration
     *
     * @return string Generated HTML for error container
     */
    public function renderErrorContainer(string $message, array $layoutConfig)
    {
        return $this->twig->render('partials/containers/errors-container.html.twig', [
            'content' => [
                'error' => $message,
            ],
            'layout' => $layoutConfig,
        ]);
    }

    /**
     * Render error card for failed servers.
     *
     * @param string              $server       Server name
     * @param array<string,mixed> $serverConfig Server configuration
     * @param Exception           $exception    The error that occurred
     *
     * @return string Generated HTML for error card
     */
    public function renderErrorCard(string $server, array $serverConfig, Exception $exception): string
    {
        $this->logError($exception->getMessage(), 'ServerCard', [
            'server' => $server,
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
        ]);

        $serverId = $this->generateHtmlId($server, 'server');

        $data = $this->buildServerErrorCardData(
            $serverConfig,
            $serverId,
            $exception->getMessage()
        );

        return $this->twig->render('partials/cards/server-card.html.twig', $data);
    }

    /**
     * Build complete template data for server card rendering.
     *
     * @param array<string,mixed> $serverMetrics Server metrics with thresholds
     * @param array<string,mixed> $serverConfig  Server configuration
     * @param string              $serverId      HTML ID for server
     * @param string              $appsHtml      Rendered apps HTML
     *
     * @return array<string,mixed> Template data array
     */
    private function buildServerCardData(
        string $serverName,
        array $serverMetrics,
        array $serverConfig,
        string $serverId,
        string $appsHtml,
        array $nodeHealth = []
    ): array {
        $data = $this->buildServerStatsData($serverName, $serverMetrics, $serverConfig, $serverId, $nodeHealth);

        $data['content'] = [
            'apps_status' => $appsHtml,
        ];

        $data['endpoints'] = [
            'modal_server_info_render' => $this->endpoints->url(
                'modal_server_info_render',
                ['server' => $serverName]
            ),
        ];

        return $data;
    }

    /**
     * Build common server template data shared by card and stats rendering.
     *
     * @param array<string,mixed> $serverMetrics Server metrics with thresholds
     * @param array<string,mixed> $serverConfig  Server configuration
     * @param string              $serverId      HTML ID for server
     *
     * @return array<string,mixed> Template data array
     */
    private function buildServerStatsData(
        string $serverName,
        array $serverMetrics,
        array $serverConfig,
        string $serverId,
        array $nodeHealth = []
    ): array {
        $serverData = $serverMetrics['data'];
        $levels = $serverMetrics['levels'];
        $cacheInfo = $serverMetrics['cache'] ?? ['age' => 0, 'ttl' => 0];

        $basicServerData = $this->buildBasicServerData($serverConfig, $serverId, $serverData, $nodeHealth);
        $basicServerData['title'] = $this->truncate($basicServerData['title'], 20);
        $basicServerData['name'] = $this->truncate($serverName, 20);

        return [
            'server' => $basicServerData,
            'storage' => $this->buildStorageData($serverData, $levels),
            'processes' => $this->buildProcessData($serverData),
            'files' => [
                'count' => $this->formatCompactNumber($serverData['total_system_filecount'] ?? 0),
                'count_full' => $this->formatNumber($serverData['total_system_filecount'] ?? 0),
                'count_level' => $levels['total_system_filecount'],
            ],
            'cronjobs' => $this->buildCronjobsStatsData($serverName, $serverMetrics),
            'icons' => $this->getCommonIcons(),
            'cache' => [
                'age' => $this->tools->formatDuration((int) ($cacheInfo['age'] ?? 0)),
                'ttl' => $this->tools->formatDuration((int) ($cacheInfo['ttl'] ?? 0)),
            ],
        ];
    }

    /**
     * Build cronjobs stats template data.
     *
     * @param array<string,mixed> $serverMetrics Server metrics with cronjob badge data
     *
     * @return array<string,mixed> Cronjobs stats data
     */
    private function buildCronjobsStatsData(string $serverName, array $serverMetrics): array
    {
        $cronjobs = is_array($serverMetrics['cronjobs'] ?? null) ? $serverMetrics['cronjobs'] : [
            'visible' => true,
            'level' => 'alarm',
            'count' => '!',
            'tooltip' => 'Cronjob data unavailable',
        ];

        $cronjobs['modal_endpoint'] = $this->endpoints->url(
            'modal_server_cronjobs_render',
            ['server' => $serverName]
        );

        return $cronjobs;
    }

    /**
     * Build template data for error server card.
     *
     * @param array<string,mixed>      $serverConfig Server configuration
     * @param string                   $serverId     HTML ID for server
     * @param string                   $errorMsg     Error message
     * @param null|array<string,mixed> $serverData   Partial server data if available
     *
     * @return array<string,mixed> Template data array
     */
    private function buildServerErrorCardData(
        array $serverConfig,
        string $serverId,
        string $errorMsg,
        ?array $serverData = null,
        array $nodeHealth = []
    ): array {
        $basicServerData = $this->buildBasicServerData($serverConfig, $serverId, $serverData, $nodeHealth);
        $basicServerData['title'] = $this->truncate($basicServerData['title']);

        return [
            'server' => $basicServerData,
            'storage' => $this->buildStorageData($serverData),
            'icons' => $this->getCommonIcons(),
            'error' => [
                'message' => "Error: {$errorMsg}",
            ],
        ];
    }

    /**
     * Build template data for individual app card.
     *
     * @param string              $app          Application name/key
     * @param array<string,mixed> $appData      Application data from API
     * @param string              $server       Server name
     * @param array<string,mixed> $logAnalysis  Log analysis results
     * @param array<string,mixed> $sessionData  Session data with levels
     * @param int                 $appsCacheAge Apps cache age
     * @param int                 $appsCacheTtl Apps cache TTL
     * @param array<string,mixed> $availability Availability details (reachable, latency, httpCode, error)
     *
     * @return array<string,mixed> Template data array
     */
    private function buildAppCardData(
        string $app,
        array $appData,
        string $server,
        array $logAnalysis,
        array $sessionData,
        int $appsCacheAge,
        int $appsCacheTtl,
        array $availability = []
    ): array {
        $appDivId = bin2hex(random_bytes(3));
        $availabilityView = $this->formatAvailability($availability, $appData);

        return [
            'app' => [
                'key' => $app,
                'name' => $this->truncate($appData['title'], 19),
                'url' => $appData['url'],
                'url_tooltip' => Html::escapeTooltipValue($appData['url'] ?? ''),
                'id' => $appDivId,
                'server' => $server,
                'env' => $appData['env'] ?? '',
                'path' => $appData['path'],
                'path_tooltip' => Html::escapeTooltipValue($appData['path'] ?? ''),
                'ping' => $availabilityView['status'],
                'ping_latency' => $availabilityView['tooltip'],
                'status_count' => $logAnalysis['error_analysis']['show_events_count'],
                'status_type' => $logAnalysis['error_analysis']['show_events_type'],
                'status_tooltip' => $this->logRenderer->formatErrorAnalysisTooltip($logAnalysis['error_analysis']),
                'cache_age' => $this->tools->formatDuration($appsCacheAge),
                'cache_ttl' => $this->tools->formatDuration($appsCacheTtl),
            ],
            'icons' => $this->getCommonIcons(),
            'endpoints' => [
                'modal_app_readme_render' => $this->endpoints->url(
                    'modal_app_readme_render',
                    ['server' => $server, 'app' => $app]
                ),
                'modal_app_logs_render' => $this->endpoints->url(
                    'modal_app_logs_render',
                    ['server' => $server, 'app' => $app]
                ),
            ],
            'logs' => [
                'file_size_level' => $logAnalysis['log_analysis']['level'],
                'file_size_tooltip' => $this->logRenderer->formatLogAnalysisTooltip($logAnalysis['log_analysis']),
                'max_file_size' => $logAnalysis['log_analysis']['max_size_formatted'],
            ],
            'sessions' => [
                'count_last' => $sessionData['formatted_count'],
                'count_level' => $sessionData['level'],
                'tooltip' => $sessionData['tooltip'],
            ],
            'config' => [
                // TODO: alarms_enabled_class sounds wrong?
                'errors_alarms_enabled' => $logAnalysis['error_analysis']['alarms_enabled_class'],
            ],
        ];
    }

    /**
     * Build basic server data structure.
     *
     * @param array<string,mixed>      $serverConfig Server configuration
     * @param string                   $serverId     HTML ID for server
     * @param null|array<string,mixed> $serverData   Optional server data
     *
     * @return array<string,mixed> Basic server data structure
     */
    private function buildBasicServerData(
        array $serverConfig,
        string $serverId,
        ?array $serverData = null,
        array $nodeHealth = []
    ): array {
        return [
            'id' => $serverId,
            'title' => $serverConfig['title'] ?? '',
            'primary_color' => $serverConfig['primary_color'] ?? '#fefefe',
            'cpu_usage' => $serverData['cpu_usage'] ?? 0,
            'health' => $this->formatNodeHealth($nodeHealth),
        ];
    }

    /**
     * Create safe, compact node-health data for the title indicator.
     *
     * @param array<string,mixed> $nodeHealth
     *
     * @return array{state:string,label:string,tooltip:string}
     */
    private function formatNodeHealth(array $nodeHealth): array
    {
        $health = match ($nodeHealth['state'] ?? null) {
            NodeHealthState::HEALTHY => [
                'state' => NodeHealthState::HEALTHY,
                'label' => 'Healthy',
            ],
            NodeHealthState::UNHEALTHY => [
                'state' => NodeHealthState::UNHEALTHY,
                'label' => 'Unhealthy',
            ],
            NodeHealthState::UNREACHABLE => [
                'state' => NodeHealthState::UNREACHABLE,
                'label' => 'Unreachable',
            ],
            NodeHealthState::MAINTENANCE => [
                'state' => NodeHealthState::MAINTENANCE,
                'label' => 'Maintenance mode',
            ],
            default => [
                'state' => NodeHealthState::UNKNOWN,
                'label' => 'Health check failed',
            ],
        };
        $health['tooltip'] = implode('<br>', $this->buildHealthTooltip(
            $health['state'],
            $health['label'],
            $nodeHealth
        ));

        return $health;
    }

    /**
     * @param array<string,mixed> $nodeHealth
     *
     * @return array<int,string>
     */
    private function buildHealthTooltip(string $state, string $label, array $nodeHealth): array
    {
        if ($nodeHealth === []) {
            return [$label];
        }

        $tooltipParts = [$label];
        $checks = $nodeHealth['checks'] ?? [];
        if ($checks !== []) {
            foreach ($checks as $check => $passed) {
                $tooltipParts[] = sprintf(
                    '%s %s',
                    $passed === true ? '✓' : '✗',
                    Html::escapeTooltipValue(str_replace('_', ' ', (string) $check))
                );
            }
        }

        $timing = [];
        if (is_int($nodeHealth['latency_ms'] ?? null)) {
            $timing[] = "latency {$nodeHealth['latency_ms']} ms";
        }
        if (is_numeric($nodeHealth['checked_at'] ?? null)) {
            $age = max(0, time() - (int) $nodeHealth['checked_at']);
            $timing[] = 'checked ' . $this->tools->formatDuration($age) . ' ago';
        }
        if ($timing !== []) {
            $tooltipParts[] = implode(' · ', $timing);
        }

        if (in_array($state, [
            NodeHealthState::UNHEALTHY,
            NodeHealthState::UNREACHABLE,
            NodeHealthState::UNKNOWN,
        ], true)) {
            if (!empty($nodeHealth['httpCode'])) {
                $tooltipParts[] = 'HTTP ' . (int) $nodeHealth['httpCode'];
            }
            if (!empty($nodeHealth['error'])) {
                $tooltipParts[] = Html::escapeTooltipValue((string) $nodeHealth['error']);
            }
        }

        return $tooltipParts;
    }

    /**
     * Build basic storage data structure.
     *
     * @param null|array<string,mixed> $serverData Server data
     * @param array<string,mixed>      $levels     Threshold levels (optional)
     *
     * @return array<string,mixed> Storage data structure
     */
    private function buildStorageData(?array $serverData = null, array $levels = []): array
    {
        $diskCapacity = $serverData['disk_space_capacity'] ?? 0;
        $diskUsed = $serverData['disk_space_used'] ?? 0;
        $ramCapacity = $serverData['ram_capacity'] ?? 0;
        $ramUsed = $serverData['ram_used'] ?? 0;

        return [
            'disk_capacity' => $this->formatSize($diskCapacity),
            'disk_used' => $this->formatSize($diskUsed),
            'disk_percent' => $this->calculateUsagePercent($diskUsed, $diskCapacity),
            'disk_usage_level' => $levels['disk_space_usage_percent'] ?? 'good',
            'ram_capacity' => $this->formatSize($ramCapacity),
            'ram_used' => $this->formatSize($ramUsed),
            'ram_percent' => $this->calculateUsagePercent($ramUsed, $ramCapacity),
        ];
    }

    private function calculateUsagePercent(mixed $used, mixed $capacity): int
    {
        if (!is_numeric($used) || !is_numeric($capacity) || (float) $capacity <= 0) {
            return 0;
        }

        return (int) round((float) $used / (float) $capacity * 100);
    }

    /**
     * Build process data structure.
     *
     * @param null|array<string,mixed> $serverData Server data
     *
     * @return array<string,mixed> Process data structure
     */
    private function buildProcessData(?array $serverData = null): array
    {
        return [
            'total' => $serverData['processes']['total'] ?? 0,
            'php' => $serverData['processes']['php'] ?? 0,
            'sql' => $serverData['processes']['sql'] ?? 0,
        ];
    }

    /**
     * Convert availability data into template-ready status and tooltip.
     *
     * @param array<string,mixed> $availability
     * @param array<string,mixed> $appData
     *
     * @return array{status:string,tooltip:string}
     */
    private function formatAvailability(array $availability, array $appData): array
    {
        if (!empty($appData['maintenance'])) {
            return $this->formatMaintenanceStatus();
        }

        if (!$this->hasAvailabilityData($availability)) {
            return $this->formatProtectedStatus();
        }

        $isReachable = $availability['reachable'] ?? null;
        if ($isReachable === null) {
            return $this->formatProtectedStatus();
        }

        return $isReachable
            ? $this->formatOnlineStatus($availability)
            : $this->formatOfflineStatus($availability);
    }

    /**
     * Check if availability data is present and valid.
     *
     * @param array<string,mixed> $availability
     */
    private function hasAvailabilityData(array $availability): bool
    {
        return !empty($availability);
    }

    /**
     * Format maintenance status response.
     *
     * @return array{status:string,tooltip:string}
     */
    private function formatMaintenanceStatus(): array
    {
        return [
            'status' => AppAvailabilityStatus::MAINTENANCE,
            'tooltip' => 'Maintenance mode',
        ];
    }

    /**
     * Format protected/no-data status response.
     *
     * @return array{status:string,tooltip:string}
     */
    private function formatProtectedStatus(): array
    {
        return [
            'status' => AppAvailabilityStatus::PROTECTED,
            'tooltip' => 'No availability data',
        ];
    }

    /**
     * Format online status with latency and HTTP code details.
     *
     * @param array<string,mixed> $availability
     *
     * @return array{status:string,tooltip:string}
     */
    private function formatOnlineStatus(array $availability): array
    {
        $latency = (int) ($availability['latency_ms'] ?? 0);
        $httpCode = $availability['httpCode'] ?? null;

        $segments = [];
        if ($latency > 0) {
            $segments[] = "{$latency} ms";
        }
        if ($httpCode) {
            $segments[] = "HTTP {$httpCode}";
        }

        $tooltip = $segments === []
            ? 'Online'
            : 'Online: ' . implode(' • ', $segments);

        return [
            'status' => AppAvailabilityStatus::ONLINE,
            'tooltip' => $tooltip,
        ];
    }

    /**
     * Format offline status with error and HTTP code details.
     *
     * @param array<string,mixed> $availability
     *
     * @return array{status:string,tooltip:string}
     */
    private function formatOfflineStatus(array $availability): array
    {
        $error = trim((string) ($availability['error'] ?? 'Unavailable'));
        $httpCode = $availability['httpCode'] ?? null;

        $segments = [];
        if ($error !== '') {
            $segments[] = Html::escapeTooltipValue($error);
        }
        if ($httpCode) {
            $segments[] = "HTTP {$httpCode}";
        }

        $tooltip = $segments === []
            ? 'Offline'
            : implode(' • ', $segments);

        return [
            'status' => AppAvailabilityStatus::OFFLINE,
            'tooltip' => $tooltip,
        ];
    }

    /**
     * Render complete dashboard page with navigation and icons.
     *
     * @param array<string,mixed>            $notification Active notification data
     * @param array<string,mixed>            $endpoints    Route endpoints
     * @param array<int,array<string,mixed>> $menu         Navigation menu items
     * @param array<string,mixed>            $layout       Layout configuration overrides
     *
     * @return string Rendered HTML for dashboard page
     */
    public function renderDashboardPage(
        array $notification,
        array $endpoints,
        array $menu,
        array $layout = []
    ): string {
        $templateData = $this->buildDashboardPageData($notification, $endpoints, $menu, $layout);

        return $this->twig->render('pages/start.html.twig', $templateData);
    }

    /**
     * Build template data for main dashboard page.
     *
     * @param array<string,mixed>            $notification Active notification data
     * @param array<string,mixed>            $endpoints    Route endpoints
     * @param array<int,array<string,mixed>> $menu         Navigation menu items
     * @param array<string,mixed>            $layout       Layout configuration overrides
     *
     * @return array<string,mixed> Template data array
     */
    public function buildDashboardPageData(
        array $notification,
        array $endpoints,
        array $menu,
        array $layout = []
    ): array {
        $notificationEnabled = !empty($notification);
        $notificationId = (string) ($notification['id'] ?? 'dashboard-notification');
        $notificationActions = [];
        if (isset($notification['actions']) && is_array($notification['actions'])) {
            foreach ($notification['actions'] as $action) {
                if (is_array($action)) {
                    $notificationActions[] = $this->mapNotificationAction($action, $endpoints);
                }
            }
        }

        $notificationCloseEndpoint = null;
        if (isset($notification['close_endpoint']) && is_string($notification['close_endpoint'])) {
            $notificationCloseEndpoint = $this->resolveEndpointUrl($notification['close_endpoint'], $endpoints);
        }

        $layoutDefaults = [
            'server_columns' => 2,
            'container_width' => 1344,
        ];

        $layoutOverrides = array_filter(
            $layout,
            static fn ($value) => $value !== null
        );

        return [
            'app_env' => $layout['app_env'] ?? 'prod',
            'ui' => [
                'main_logo' => $this->tools->icon('turtle', 12),
                'notification_enabled' => $notificationEnabled,
                'notification_type' => $notification['type'] ?? 'info',
                'notification_title' => $notification['title'] ?? '',
                'notification_message' => $notification['message'] ?? '',
                'notification_id' => $notificationId,
                'notification_actions' => $notificationActions,
                'notification_close_action' => $notification['close_action'] ?? null,
                'notification_close_endpoint' => $notificationCloseEndpoint,
            ],
            'config' => [
                'base_url' => $this->app->getBaseUrl(),
                'full_base_url' => $this->app->getFullBaseUrl(),
            ],
            'endpoints' => $endpoints,
            'menu' => $menu,
            'layout' => array_merge($layoutDefaults, $layoutOverrides),
        ];
    }

    /**
     * Normalize notification action endpoint references to usable URLs.
     *
     * @param array<string,mixed> $action
     * @param array<string,mixed> $endpoints
     *
     * @return array<string,mixed>
     */
    private function mapNotificationAction(array $action, array $endpoints): array
    {
        if (!isset($action['endpoint']) || !is_string($action['endpoint'])) {
            return $action;
        }

        $endpoint = $action['endpoint'];
        if (!str_starts_with($endpoint, '/')) {
            if (isset($endpoints[$endpoint])) {
                $action['endpoint'] = $endpoints[$endpoint];
            } else {
                unset($action['endpoint']);
            }
        }

        return $action;
    }

    /**
     * Resolve a named endpoint reference to a usable URL.
     *
     * @param array<string,mixed> $endpoints
     */
    private function resolveEndpointUrl(string $endpoint, array $endpoints): string
    {
        if (str_starts_with($endpoint, '/')) {
            return $endpoint;
        }

        return $endpoints[$endpoint] ?? '';
    }

    /**
     * Delegate log rendering to LogRenderer.
     *
     * @param array<string> $logData Array of log entries
     *
     * @return string HTML formatted log content
     */
    public function renderLogs(array $logData): string
    {
        return $this->logRenderer->renderLogs($logData);
    }

    /**
     * Delegate process table rendering to LogRenderer.
     *
     * @param array<string,mixed> $phpProcessData Array of PHP process information
     *
     * @return string HTML formatted PHP process table
     */
    public function renderProcessTable(array $phpProcessData): string
    {
        return $this->logRenderer->renderProcessTable($phpProcessData);
    }
}
