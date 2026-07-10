<?php

declare(strict_types=1);

namespace Mata\Services\View;

use Exception;
use Mata\Constants\CacheTypes;
use Mata\Constants\NodeHealthState;
use Mata\Services\Cache\CacheTypeResolver;
use Mata\Services\Collect\ServerDataCollectorInterface;
use Mata\Services\Event\EventManager;
use Mata\Services\Foundation\LogAnalyzer;
use Mata\Services\Monitor\AppMonitorInterface;
use Mata\Services\Monitor\Dto\EndpointTarget;
use Mata\Services\Monitor\NodeHealthMonitorInterface;
use Mata\Services\Monitor\ServerMonitor;
use Mata\Utils\Html;
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

    public function __construct(
        ServerMonitor $serverMonitor,
        LogAnalyzer $logAnalyzer,
        EventManager $eventManager,
        Logger $logger,
        CacheTypeResolver $cacheTypeResolver
    ) {
        $this->serverMonitor = $serverMonitor;
        $this->logAnalyzer = $logAnalyzer;
        $this->eventManager = $eventManager;
        $this->logger = $logger;
        $this->cacheTypeResolver = $cacheTypeResolver;
    }

    /**
     * Build view data for all configured servers.
     *
     * @param array<string,ServerDataCollectorInterface> $serversDataCollector
     * @param array<string,AppMonitorInterface>          $appMonitors
     * @param array<string,NodeHealthMonitorInterface>   $healthMonitors
     * @param array<string,mixed>                        $serversConfig
     *
     * @return array<int,array<string,mixed>>
     */
    public function buildServersViewData(
        array $serversDataCollector,
        array $appMonitors,
        array $healthMonitors,
        array $serversConfig,
        bool $forceRefresh
    ): array {
        $serversViewData = [];

        foreach ($serversConfig as $server => $serverConfig) {
            $healthMonitor = $healthMonitors[$server] ?? null;

            try {
                $collector = $serversDataCollector[$server] ?? null;
                $appMonitor = $appMonitors[$server] ?? null;

                if (!$collector instanceof ServerDataCollectorInterface || !$appMonitor instanceof AppMonitorInterface) {
                    throw new Exception("Monitoring services unavailable for server {$server}");
                }

                $serversViewData[] = $this->buildServerViewData(
                    $server,
                    $serverConfig,
                    $collector,
                    $appMonitor,
                    $forceRefresh,
                    $healthMonitor,
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
                    $this->resolveNodeHealth($server, $healthMonitor)
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
        $serverData['health'] = $this->resolveNodeHealth($serverName, $healthMonitor);

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
            $targets = EndpointTarget::fromRawAppData($rawAppsData, $serverName);

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
            return $healthMonitor->check($serverName);
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
                'formatted_count' => $this->formatNumber($lastHour),
                'tooltip' => implode('<br>', $tooltipParts),
            ];
        }

        if ($status === 'ok') {
            $total = $session['count_total'];

            return [
                'level' => '',
                'formatted_count' => $this->formatNumber($total),
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
