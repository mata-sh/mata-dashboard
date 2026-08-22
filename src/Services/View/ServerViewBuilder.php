<?php

declare(strict_types=1);

namespace Mata\Services\View;

use Exception;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\Utils;
use InvalidArgumentException;
use Mata\Constants\CacheTypes;
use Mata\Constants\NodeHealthState;
use Mata\Services\Cache\CacheTypeResolver;
use Mata\Services\Collect\ServerDataCollectorInterface;
use Mata\Services\Event\EventManager;
use Mata\Services\Monitor\AppReachabilityMonitorInterface;
use Mata\Services\Monitor\Dto\AppFatalErrorPolicy;
use Mata\Services\Monitor\Dto\AppMonitoringSettings;
use Mata\Services\Monitor\Dto\EndpointTarget;
use Mata\Services\Monitor\Dto\ServerMonitoringServices;
use Mata\Services\Monitor\LogSizeEvaluator;
use Mata\Services\Monitor\NodeHealthMonitorInterface;
use Mata\Services\Monitor\ServerMonitor;
use Mata\Services\Sync\AppInventorySync;
use Mata\Views\Support\Html;
use Mata\Views\Support\NumberFormatter;
use Monolog\Logger;
use Throwable;

/**
 * Server view data builder service.
 *
 * Orchestrates data collection from multiple services to build
 * view-ready data structures for server rendering:
 * - Coordinates server, application, and log view data
 * - Handles error cases and partial data
 * - Enriches app data with metrics and availability
 * - Formats data for template consumption
 */
class ServerViewBuilder
{
    private ServerMonitor $serverMonitor;
    private LogSizeEvaluator $logSizeEvaluator;
    private EventManager $eventManager;
    private Logger $logger;
    private CacheTypeResolver $cacheTypeResolver;
    private ?AppInventorySync $appInventorySync;
    private NumberFormatter $numberFormatter;

    public function __construct(
        ServerMonitor $serverMonitor,
        LogSizeEvaluator $logSizeEvaluator,
        EventManager $eventManager,
        Logger $logger,
        CacheTypeResolver $cacheTypeResolver,
        ?AppInventorySync $appInventorySync = null,
        ?NumberFormatter $numberFormatter = null,
    ) {
        $this->serverMonitor = $serverMonitor;
        $this->logSizeEvaluator = $logSizeEvaluator;
        $this->eventManager = $eventManager;
        $this->logger = $logger;
        $this->cacheTypeResolver = $cacheTypeResolver;
        $this->appInventorySync = $appInventorySync;
        $this->numberFormatter = $numberFormatter ?? new NumberFormatter();
    }

    /**
     * Build view data for all configured servers.
     *
     * @param array<string,ServerMonitoringServices> $monitoringServicesByServer
     * @param array<string,mixed>                    $serversConfig
     *
     * @return array<int,array<string,mixed>>
     */
    public function buildServersViewData(
        array $monitoringServicesByServer,
        array $serversConfig,
        bool $forceRefresh
    ): array {
        $serversViewData = [];
        $healthByServer = $this->resolveNodeHealthByServer($monitoringServicesByServer, $serversConfig);

        foreach ($serversConfig as $server => $serverConfig) {
            $monitoringServices = $monitoringServicesByServer[$server] ?? null;

            try {
                if (!$monitoringServices instanceof ServerMonitoringServices) {
                    throw new Exception("Monitoring services unavailable for server {$server}");
                }

                $serversViewData[] = $this->buildServerViewData(
                    $server,
                    $serverConfig,
                    $monitoringServices->collector,
                    $monitoringServices->reachabilityMonitor,
                    $forceRefresh,
                    $monitoringServices->healthMonitor,
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
        AppReachabilityMonitorInterface $reachabilityMonitor,
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
        if ($forceRefresh) {
            $this->appInventorySync?->sync($rawAppsData);
        }
        $appsCacheAge = (int) ($appsApiResponse['cache_age'] ?? 0);
        $appsCacheTtl = (int) ($appsApiResponse['cache_ttl'] ?? 0);

        $appsAvailability = $this->checkAppsAvailability($rawAppsData, $reachabilityMonitor, $serverName);
        $appsViewData = $this->enrichAppsWithMetrics(
            $rawAppsData,
            $appsAvailability,
            $serverName,
            $collector,
            $this->buildFatalErrorPolicy($serverName, $serverConfig),
        );

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
        AppReachabilityMonitorInterface $reachabilityMonitor,
        string $serverName,
    ): array {

        if ($rawAppsData === []) {
            return [];
        }

        try {
            $targets = EndpointTarget::fromRawAppData($rawAppsData, $serverName);

            return $reachabilityMonitor->checkMultipleEndpoints($targets);
        } catch (Exception $e) {
            $this->logger->warning(
                "Availability checks failed for {$serverName}",
                ['error' => $e->getMessage()]
            );

            return [];
        }
    }

    /**
     * Build the fatal-error alarm policy for a server, or null when unusable.
     *
     * @param array<string,mixed> $serverConfig
     */
    private function buildFatalErrorPolicy(string $serverName, array $serverConfig): ?AppFatalErrorPolicy
    {
        try {
            return AppFatalErrorPolicy::fromServerConfig($serverConfig);
        } catch (InvalidArgumentException $e) {
            $this->logger->warning(
                "Invalid error_monitoring_enabled for {$serverName}",
                ['error' => $e->getMessage()]
            );

            return null;
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
        ServerDataCollectorInterface $collector,
        ?AppFatalErrorPolicy $fatalErrorPolicy = null
    ): array {
        $appsViewData = [];

        foreach ($rawAppsData as $appKey => $appData) {
            $monitoring = AppMonitoringSettings::fromAppPayload($appData, $this->logger);

            $logAnalysis = $this->buildAppLogViewData(
                $appData,
                $this->resolveErrorAlarmsEnabled($fatalErrorPolicy, $appData),
                $monitoring,
            );

            $sessionData = $this->formatSessionDataForView($appData, $monitoring);

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
     * Resolve the effective fatal-error alarm state for presentation.
     *
     * Invalid configuration is authoritatively reported by the monitoring cron,
     * which logs it per server/app; the view must never fail to render over it.
     *
     * @param array<string,mixed> $appData
     */
    private function resolveErrorAlarmsEnabled(?AppFatalErrorPolicy $policy, array $appData): bool
    {
        if (!$policy instanceof AppFatalErrorPolicy) {
            return true;
        }

        try {
            return $policy->isEnabled($appData);
        } catch (InvalidArgumentException) {
            return true;
        }
    }

    /**
     * @param array<string,mixed> $appData
     *
     * @return array<string,mixed>
     */
    private function buildAppLogViewData(
        array $appData,
        bool $errorAlarmsEnabled = true,
        ?AppMonitoringSettings $monitoring = null
    ): array {
        $monitoring ??= AppMonitoringSettings::allEnabled();
        $logAnalysisEnabled = $monitoring->isEnabled('loganalysis');
        $logSizeEnabled = $monitoring->isEnabled('logsize');

        $logs = is_array($appData['logs'] ?? null) ? $appData['logs'] : [];
        $status = is_array($appData['status'] ?? null) ? $appData['status'] : [];
        $fatal = (int) ($status['fatal'] ?? 0);
        $warnings = (int) ($status['warnings'] ?? 0);
        $total = (int) ($status['total'] ?? 0);

        $showEventsCount = 0;
        $showEventsType = 'working';

        if ($logAnalysisEnabled) {
            $fatalCount = $this->numberFormatter->formatCompact($fatal);
            $warningCount = $this->numberFormatter->formatCompact($warnings);
            $totalLineCount = $this->numberFormatter->formatCompact($total);

            if ($fatal > 0) {
                $showEventsCount = $fatalCount;
                $showEventsType = 'errors';
            } elseif ($warnings > 0) {
                $showEventsCount = $warningCount;
                $showEventsType = 'warnings';
            }
        } else {
            // Unscanned counts are not zero.
            $fatalCount = '–';
            $warningCount = '–';
            $totalLineCount = '–';
            $fatal = 0;
            $warnings = 0;
            $total = 0;
        }

        $firstLog = '';
        foreach ($logs as $log => $size) {
            $firstLog = (string) $log;

            break;
        }

        $logSizeView = $logSizeEnabled
            ? $this->logSizeEvaluator->evaluate($logs)->toArray()
            : $this->disabledLogSizeViewData();
        $logSizeView['logsize_monitoring_enabled'] = $logSizeEnabled;

        return [
            'log_analysis' => $logSizeView,
            'error_analysis' => [
                'fatal_count' => $fatalCount,
                'warning_count' => $warningCount,
                'total_count' => $totalLineCount,
                'show_events_count' => $showEventsCount,
                'show_events_type' => $showEventsType,
                'fatal_count_raw' => $fatal,
                'warning_count_raw' => $warnings,
                'total_count_raw' => $total,
                'first_log' => $firstLog,
                'error_monitoring_enabled' => $errorAlarmsEnabled,
                'loganalysis_enabled' => $logAnalysisEnabled,
                'alarms_enabled' => $monitoring->isEnabled('alarms'),
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function disabledLogSizeViewData(): array
    {
        return [
            'max_size' => 0,
            'max_size_formatted' => '–',
            'max_path' => null,
            'level' => '',
            'first_log' => '',
            'files' => [],
        ];
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
     * @param array<string,ServerMonitoringServices> $monitoringServicesByServer
     * @param array<string,mixed>                    $serversConfig
     *
     * @return array<string,array<string,mixed>>
     */
    private function resolveNodeHealthByServer(array $monitoringServicesByServer, array $serversConfig): array
    {
        $healthByServer = [];
        $pending = [];

        foreach (array_keys($serversConfig) as $server) {
            $services = $monitoringServicesByServer[$server] ?? null;
            if (!$services instanceof ServerMonitoringServices) {
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
            return $healthMonitor->checkSynchronously($serverName);
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
            'state' => NodeHealthState::Unknown->value,
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
    private function formatSessionDataForView(array $appData, ?AppMonitoringSettings $monitoring = null): array
    {
        if ($monitoring instanceof AppMonitoringSettings && !$monitoring->isEnabled('sessions')) {
            return [
                'level' => '',
                'formatted_count' => '–',
                'tooltip' => 'Session monitoring disabled',
            ];
        }

        $session = is_array($appData['session'] ?? null) ? $appData['session'] : [];
        $status = $session['status'] ?? 'unconfigured';

        if ($status === 'ok' && ($session['count_last_hour'] ?? null) !== null) {
            $lastHour = $session['count_last_hour'];
            $tooltipParts = [];

            $tooltipParts[] = sprintf('<strong>Type:</strong> %s', Html::escapeTooltipValue($session['type'] ?? ''));

            if (($session['path'] ?? null) !== null) {
                $tooltipParts[] = Html::escapeTooltipValue($session['path']);
            }

            foreach ([
                '1 Hour' => $lastHour,
                '24 Hours' => $session['count_last_day'] ?? 0,
                'Total' => $session['count_total'] ?? 0,
            ] as $label => $count) {
                $tooltipParts[] = sprintf(
                    '<strong>%s:</strong> %s',
                    $label,
                    $this->formatNumber($count)
                );
            }

            if (($session['size_kb'] ?? null) !== null) {
                $tooltipParts[] = sprintf('<strong>Size:</strong> %s KB', (string) $session['size_kb']);
            }

            return [
                'level' => $this->eventManager->getAppDataThresholdLevel($lastHour, 'session_count'),
                'formatted_count' => $this->numberFormatter->formatCompact($lastHour),
                'tooltip' => implode('<br>', $tooltipParts),
            ];
        }

        if ($status === 'ok') {
            $total = $session['count_total'] ?? 0;

            return [
                'level' => '',
                'formatted_count' => $this->numberFormatter->formatCompact($total),
                'tooltip' => sprintf(
                    '<strong>Type:</strong> %s<br><strong>Total:</strong> %s<br>Hourly/daily counts not available',
                    Html::escapeTooltipValue($session['type'] ?? ''),
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
                Html::escapeTooltipValue($session['type'] ?? ''),
                Html::escapeTooltipValue($session['error'] ?? '')
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
