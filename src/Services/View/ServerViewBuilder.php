<?php

declare(strict_types=1);

namespace Mata\Services\View;

use Exception;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\Utils;
use Mata\Constants\CacheTypes;
use Mata\Constants\NodeHealthState;
use Mata\Services\Cache\CacheTypeResolver;
use Mata\Services\Collect\ServerDataCollectorInterface;
use Mata\Services\Event\EventManager;
use Mata\Services\Foundation\LogAnalyzer;
use Mata\Services\Monitor\AppMonitorInterface;
use Mata\Services\Monitor\Dto\EndpointTarget;
use Mata\Services\Monitor\Dto\ServerDashboardServices;
use Mata\Services\Monitor\NodeHealthMonitorInterface;
use Mata\Services\Monitor\ServerMonitor;
use Mata\Utils\Html;
use Mata\Utils\Tools;
use Monolog\Logger;
use Throwable;

/**
 * Server view data builder service.
 *
 * Orchestrates data collection from multiple services to build
 * view-ready data structures for server rendering:
 * - Coordinates ServerMonitor, AppMonitor, LogAnalyzer
 * - Handles error cases and partial data
 * - Enriches app data with metrics and availability
 * - Formats data for template consumption
 */
class ServerViewBuilder
{
    private ServerMonitor $serverMonitor;
    private LogAnalyzer $logAnalyzer;
    private EventManager $eventManager;
    private Logger $logger;
    private CacheTypeResolver $cacheTypeResolver;
    private Tools $tools;

    public function __construct(
        ServerMonitor $serverMonitor,
        LogAnalyzer $logAnalyzer,
        EventManager $eventManager,
        Logger $logger,
        CacheTypeResolver $cacheTypeResolver,
        Tools $tools
    ) {
        $this->serverMonitor = $serverMonitor;
        $this->logAnalyzer = $logAnalyzer;
        $this->eventManager = $eventManager;
        $this->logger = $logger;
        $this->cacheTypeResolver = $cacheTypeResolver;
        $this->tools = $tools;
    }

    /**
     * Build view data for all configured servers.
     *
     * @param array<string,ServerDashboardServices> $dashboardServicesByServer
     * @param array<string,mixed>                   $serversConfig
     *
     * @return array<int,array<string,mixed>>
     */
    public function buildServersViewData(
        array $dashboardServicesByServer,
        array $serversConfig,
        bool $forceRefresh
    ): array {
        $serversViewData = [];
        $healthByServer = $this->resolveNodeHealthByServer($dashboardServicesByServer, $serversConfig);

        foreach ($serversConfig as $server => $serverConfig) {
            $dashboardServices = $dashboardServicesByServer[$server] ?? null;

            try {
                if (!$dashboardServices instanceof ServerDashboardServices) {
                    throw new Exception("Monitoring services unavailable for server {$server}");
                }

                $serversViewData[] = $this->buildServerViewData(
                    $server,
                    $serverConfig,
                    $dashboardServices->collector,
                    $dashboardServices->appMonitor,
                    $forceRefresh,
                    $dashboardServices->healthMonitor,
                    $healthByServer[$server],
                );
            } catch (Exception $e) {
                $this->logger->error("{$server}", [
                    'msg' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);

                $serversViewData[] = $this->buildServerErrorViewData(
                    $server,
                    $serverConfig,
                    $e,
                    $healthByServer[$server] ?? $this->unknownHealth()
                );
            }
        }

        return $serversViewData;
    }

    /**
     * Build server metrics data without app information.
     *
     * Collects only server health metrics (CPU, RAM, disk, processes, files)
     * and applies threshold evaluation. Use this for stats-only rendering
     * where app data is not needed.
     *
     * @param array<string,mixed> $serverConfig
     *
     * @return array<string,mixed>
     */
    public function buildServerStatsData(
        string $serverName,
        array $serverConfig,
        ServerDataCollectorInterface $collector,
        bool $forceRefresh
    ): array {
        // Fetch server metrics only
        $metricsCacheType = $this->cacheTypeResolver->resolve($forceRefresh, CacheTypes::SERVER_DATA);
        $serverMetrics = $this->serverMonitor->collectServerHealthData(
            $collector,
            $serverName,
            $metricsCacheType
        );

        return [
            'server' => $serverName,
            'config' => $serverConfig,
            'metrics' => $serverMetrics,
        ];
    }

    /**
     * Build complete view data for a single server including apps.
     *
     * Orchestrates collectors and monitors to build full server card data.
     * Uses buildServerStatsData() as base and extends with expensive
     * app monitoring, log analysis, and availability checks.
     *
     * @param array<string,mixed> $serverConfig
     *
     * @return array<string,mixed>
     */
    public function buildServerViewData(
        string $serverName,
        array $serverConfig,
        ServerDataCollectorInterface $collector,
        AppMonitorInterface $appMonitor,
        bool $forceRefresh,
        ?NodeHealthMonitorInterface $healthMonitor = null,
        ?array $resolvedHealth = null,
    ): array {
        // Get base server metrics
        $serverData = $this->buildServerStatsData($serverName, $serverConfig, $collector, $forceRefresh);

        // Fetch apps list
        $appsCacheType = $this->cacheTypeResolver->resolve($forceRefresh, CacheTypes::APPS);
        $appsApiResponse = $collector->getApps($appsCacheType);
        $appsError = is_array($appsApiResponse) ? ($appsApiResponse['error'] ?? null) : null;
        if (is_array($appsError) && is_string($appsError['message'] ?? null)) {
            throw new Exception($appsError['message']);
        }

        if ($appsApiResponse === null || !isset($appsApiResponse['result']) || !is_array($appsApiResponse['result'])) {
            throw new Exception('Failed to fetch apps list via API.');
        }

        $rawAppsData = $appsApiResponse['result'];
        $appsCacheAge = (int) ($appsApiResponse['cache_age'] ?? 0);
        $appsCacheTtl = (int) ($appsApiResponse['cache_ttl'] ?? 0);

        $appsAvailability = $this->checkAppsAvailability($rawAppsData, $appMonitor, $serverName);
        $appsViewData = $this->enrichAppsWithMetrics($rawAppsData, $appsAvailability, $serverName, $collector);

        // Extend base data with app information
        $serverData['apps'] = $appsViewData;
        $serverData['apps_cache'] = [
            'age' => $appsCacheAge,
            'ttl' => $appsCacheTtl,
        ];
        $serverData['health'] = $resolvedHealth ?? $this->resolveNodeHealth($serverName, $healthMonitor);

        return $serverData;
    }

    /**
     * Check availability for multiple app URLs.
     *
     * @param array<string,array<string,mixed>> $rawAppsData
     *
     * @return array<string,mixed>
     */
    private function checkAppsAvailability(
        array $rawAppsData,
        AppMonitorInterface $appMonitor,
        string $serverName,
    ): array {

        if ($rawAppsData === []) {
            return [];
        }

        try {
            $pingableApps = array_filter(
                $rawAppsData,
                static fn (array $app): bool => ($app['ping'] ?? 'on') !== 'off'
            );
            $targets = EndpointTarget::fromRawAppData($pingableApps, $serverName);

            return $appMonitor->checkMultipleEndpoints($targets);
        } catch (Exception $e) {
            $this->logger->warning(
                "Availability checks failed for {$serverName}",
                ['error' => $e->getMessage()]
            );

            return [];
        }
    }

    /**
     * Prepare app view data with enrichments (logs, sessions, availability).
     *
     * @param array<string,mixed> $rawAppsData
     * @param array<string,mixed> $availabilityResults
     *
     * @return array<int,array<string,mixed>>
     */
    private function enrichAppsWithMetrics(
        array $rawAppsData,
        array $availabilityResults,
        string $serverName,
        ServerDataCollectorInterface $collector
    ): array {
        $appsViewData = [];

        foreach ($rawAppsData as $appKey => $appData) {
            $alarmsEnabled = ($appData['alarms_enabled'] ?? 'on') !== 'off';

            $logAnalysis = $this->logAnalyzer->analyzeAppLogs(
                $appData,
                $appKey,
                $serverName,
                $collector,
                $alarmsEnabled
            );

            $sessionData = $this->formatSessionDataForView($appData);

            $appsViewData[] = [
                'key' => $appKey,
                'data' => $appData,
                'logAnalysis' => $logAnalysis,
                'session' => $sessionData,
                'availability' => $availabilityResults[$appKey] ?? [],
            ];
        }

        return $appsViewData;
    }

    /**
     * Build error view data when server monitoring fails.
     *
     * @param array<string,mixed> $serverConfig
     *
     * @return array<string,mixed>
     */
    private function buildServerErrorViewData(
        string $serverName,
        array $serverConfig,
        Exception $exception,
        array $nodeHealth
    ): array {
        return [
            'server' => $serverName,
            'config' => $serverConfig,
            'health' => $nodeHealth,
            'error' => $exception->getMessage(),
        ];
    }

    /**
     * Resolve node health for every configured server.
     *
     * Cached values return immediately; cache misses are settled together.
     *
     * @param array<string,ServerDashboardServices> $dashboardServicesByServer
     * @param array<string,mixed>                   $serversConfig
     *
     * @return array<string,array<string,mixed>>
     */
    private function resolveNodeHealthByServer(array $dashboardServicesByServer, array $serversConfig): array
    {
        $healthByServer = [];
        $pending = [];

        foreach (array_keys($serversConfig) as $server) {
            $services = $dashboardServicesByServer[$server] ?? null;
            if (!$services instanceof ServerDashboardServices) {
                $healthByServer[$server] = $this->unknownHealth();

                continue;
            }

            try {
                $result = $services->healthMonitor->checkAsync($server);
                if ($result instanceof PromiseInterface) {
                    $pending[$server] = $result;
                } else {
                    $healthByServer[$server] = $result;
                }
            } catch (Throwable $e) {
                $this->logger->warning("Health check failed for {$server}", ['error' => $e->getMessage()]);
                $healthByServer[$server] = $this->unknownHealth();
            }
        }

        if ($pending === []) {
            return $healthByServer;
        }

        foreach (Utils::settle($pending)->wait() as $server => $outcome) {
            if ($outcome['state'] === 'fulfilled' && is_array($outcome['value'])) {
                $healthByServer[$server] = $outcome['value'];

                continue;
            }

            $reason = $outcome['reason'] ?? null;
            $message = $reason instanceof Throwable ? $reason->getMessage() : 'Unknown health probe failure';
            $this->logger->warning("Health check failed for {$server}", ['error' => $message]);
            $healthByServer[$server] = $this->unknownHealth();
        }

        return $healthByServer;
    }

    /**
     * Health failures must not prevent server-card rendering.
     *
     * @return array<string,mixed>
     */
    private function resolveNodeHealth(string $serverName, ?NodeHealthMonitorInterface $healthMonitor): array
    {
        if ($healthMonitor === null) {
            return $this->unknownHealth();
        }

        try {
            return $healthMonitor->checkOrWait($serverName);
        } catch (Throwable $e) {
            $this->logger->warning("Health check failed for {$serverName}", ['error' => $e->getMessage()]);

            return $this->unknownHealth();
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function unknownHealth(): array
    {
        return [
            'status' => 'unreachable',
            'state' => NodeHealthState::UNKNOWN,
            'httpCode' => null,
            'maintenance_mode' => null,
            'checks' => null,
            'node_timestamp' => null,
            'latency_ms' => null,
            'error' => null,
            'checked_at' => time(),
        ];
    }

    /**
     * Format normalized session data for view rendering.
     *
     * @param array<string,mixed> $appData
     *
     * @return array<string,mixed>
     */
    private function formatSessionDataForView(array $appData): array
    {
        $session = $appData['session'];
        $status = $session['status'];

        if ($status === 'ok' && $session['count_last_hour'] !== null) {
            $lastHour = $session['count_last_hour'];
            $tooltipParts = [];

            $tooltipParts[] = sprintf('<strong>Type:</strong> %s', Html::escapeTooltipValue($session['type']));

            if ($session['path'] !== null) {
                $tooltipParts[] = Html::escapeTooltipValue($session['path']);
            }

            foreach ([
                '1 Hour' => $lastHour,
                '24 Hours' => $session['count_last_day'],
                'Total' => $session['count_total'],
            ] as $label => $count) {
                $tooltipParts[] = sprintf(
                    '<strong>%s:</strong> %s',
                    $label,
                    $this->formatNumber($count)
                );
            }

            if ($session['size_kb'] !== null) {
                $tooltipParts[] = sprintf('<strong>Size:</strong> %s KB', (string) $session['size_kb']);
            }

            return [
                'level' => $this->eventManager->getAppDataThresholdLevel($lastHour, 'session_count'),
                'formatted_count' => $this->tools->formatCompactNumber($lastHour),
                'tooltip' => implode('<br>', $tooltipParts),
            ];
        }

        if ($status === 'ok') {
            $total = $session['count_total'];

            return [
                'level' => '',
                'formatted_count' => $this->tools->formatCompactNumber($total),
                'tooltip' => sprintf(
                    '<strong>Type:</strong> %s<br><strong>Total:</strong> %s<br>Hourly/daily counts not available',
                    Html::escapeTooltipValue($session['type']),
                    $this->formatNumber($total)
                ),
            ];
        }

        if ($status === 'unconfigured') {
            return [
                'level' => '',
                'formatted_count' => '–',
                'tooltip' => 'Sessions not configured',
            ];
        }

        return [
            'level' => 'warning',
            'formatted_count' => '!',
            'tooltip' => sprintf(
                'Sessions (%s): %s',
                Html::escapeTooltipValue($session['type']),
                Html::escapeTooltipValue($session['error'])
            ),
        ];
    }

    /**
     * Format numbers with thousands separators.
     */
    private function formatNumber(float|int $number, int $decimals = 0): string
    {
        return number_format($number, $decimals, '', '.');
    }
}
