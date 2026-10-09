<?php

declare(strict_types=1);

namespace Mata\Services\Monitor;

use Exception;
use Mata\Services\Cache\CacheType;
use Mata\Services\Collect\ServerDataCollectorInterface;
use Mata\Services\Event\Dto\EventCommand;
use Mata\Services\Event\Dto\EventMetric;
use Mata\Services\Event\EventManager;
use Monolog\Logger;

/**
 * Server monitoring and threshold evaluation service.
 *
 * Handles:
 * - Server system data retrieval and caching
 * - Threshold evaluation for system metrics
 * - Alarm triggering for critical conditions
 * - File count and disk usage monitoring
 */
class ServerMonitor
{
    private Logger $logger;
    private EventManager $eventManager;

    public function __construct(
        Logger $logger,
        EventManager $eventManager,
    ) {
        $this->logger = $logger;
        $this->eventManager = $eventManager;
    }

    /**
     * Get server system metrics with threshold evaluation.
     *
     * @param ServerDataCollectorInterface $serverDataCollector Server API client
     * @param string                       $server              Server name for logging
     * @param CacheType                    $cacheType           Cache type key for TTL lookup
     *
     * @return array<string,mixed> Server metrics with threshold levels and cache metadata
     *
     * @throws Exception If server data cannot be retrieved
     */
    public function collectServerHealthData(
        ServerDataCollectorInterface $serverDataCollector,
        string $server,
        CacheType $cacheType = CacheType::ServerData
    ): array {
        $response = $serverDataCollector->getServerSystemData($cacheType);
        $error = is_array($response) ? ($response['error'] ?? null) : null;
        if (is_array($error) && is_string($error['message'] ?? null)) {
            throw new Exception($error['message']);
        }

        $serverData = $response['result'] ?? null;
        if (!is_array($serverData)) {
            $this->logger->error("Failed to fetch server data via API for server: {$server}");

            throw new Exception('Failed to fetch server data via API.');
        }

        $cacheAge = (int) ($response['cache_age'] ?? 0);
        $cacheTtl = (int) ($response['cache_ttl'] ?? 0);
        $fromCache = (bool) ($response['from_cache'] ?? false);
        $staleCache = (bool) ($response['stale_cache'] ?? false);

        // Calculate usage percentages.
        $diskSpaceUsed = $serverData['disk_space_capacity'] != 0
        ? ($serverData['disk_space_used'] / $serverData['disk_space_capacity'] * 100)
        : 0;
        $ramCapacity = (int) ($serverData['ram_capacity'] ?? 0);
        $ramUsagePercent = $ramCapacity !== 0
            ? ((int) ($serverData['ram_used'] ?? 0) / $ramCapacity * 100)
            : 0;

        $cpuUsageLevel = $this->eventManager->getServerDataThresholdLevel(
            (int) ($serverData['cpu_usage'] ?? 0),
            'cpu_usage'
        );
        $ramUsageLevel = $this->eventManager->getServerDataThresholdLevel(
            (int) $ramUsagePercent,
            'ram_usage_percent'
        );

        $this->evaluateCpuUsageAlarm((int) ($serverData['cpu_usage'] ?? 0), $cpuUsageLevel, $server);
        $this->evaluateRamUsageAlarm((int) $ramUsagePercent, $ramUsageLevel, $server);

        // Evaluate file count thresholds
        $filecountLevel = $this->eventManager->getServerDataThresholdLevel(
            (int) $serverData['total_system_filecount'],
            'total_system_filecount'
        );

        $this->evaluateFilecountAlarm($serverData, $filecountLevel, $server);

        // Evaluate disk usage thresholds
        $diskUsageLevel = $this->eventManager->getServerDataThresholdLevel(
            (int) $diskSpaceUsed,
            'disk_space_usage_percent'
        );

        $this->evaluateDiskUsageAlarm($diskSpaceUsed, $diskUsageLevel, $server);

        $cronjobsData = $this->buildCronjobsData($serverData, $server);

        return [
            'data' => $serverData,
            'cronjobs' => $cronjobsData,
            'cache' => [
                'age' => $cacheAge,
                'ttl' => $cacheTtl,
                'from_cache' => $fromCache,
                'stale' => $staleCache,
            ],
            'levels' => [
                'cpu_usage' => $cpuUsageLevel,
                'ram_usage_percent' => $ramUsageLevel,
                'total_system_filecount' => $filecountLevel,
                'disk_space_usage_percent' => $diskUsageLevel,
            ],
        ];
    }

    /**
     * Raise events for an unavailable database server or connections near max_connections.
     *
     * Nodes without database monitoring (404), node transport failures (covered by node
     * health) and stale overviews raise nothing.
     */
    public function evaluateDatabaseOverview(ServerDataCollectorInterface $serverDataCollector, string $server): void
    {
        $response = $serverDataCollector->getDatabaseOverview();
        $error = $response['error'] ?? null;
        if (is_array($error)) {
            if (($error['status_code'] ?? null) === 503) {
                $this->eventManager->handle(new EventCommand(
                    type: 'database_unreachable',
                    message: "Database server unreachable on Server: {$server}",
                    level: 'critical',
                    server: $server,
                    metric: new EventMetric('database_reachable', 0, additional: ['error' => $error['message'] ?? null]),
                ));
            }

            return;
        }

        $status = $response['result']['status'] ?? null;
        if (($response['stale_cache'] ?? false) === true || !is_array($status) || $status['max_connections'] === 0) {
            return;
        }

        $connections = (int) $status['connections'];
        $maxConnections = (int) $status['max_connections'];
        $percent = (int) ($connections / $maxConnections * 100);
        if ($this->eventManager->getServerDataThresholdLevel($percent, 'database_connections_percent') !== 'alarm') {
            return;
        }

        $this->eventManager->handle(new EventCommand(
            type: 'database_connections_too_high',
            message: "Database connections critical ({$connections}/{$maxConnections}, {$percent} %) on Server: {$server}",
            level: 'critical',
            server: $server,
            metric: new EventMetric(
                'database_connections_percent',
                $percent,
                $this->eventManager->getServerDataThresholdLimit('database_connections_percent'),
                ['connections' => $connections, 'max_connections' => $maxConnections]
            )
        ));
    }

    /**
     * Build cronjobs badge view data.
     *
     * @param array<string,mixed> $serverData Server data array
     *
     * @return array{visible:bool,level:string,count:string,tooltip:string}
     */
    private function buildCronjobsData(array $serverData, string $server): array
    {
        $cronjobs = $serverData['cronjobs'] ?? null;

        if (!is_array($cronjobs)) {
            return [
                'visible' => true,
                'level' => 'alarm',
                'count' => '!',
                'tooltip' => 'Cronjob data unavailable',
            ];
        }

        foreach (['total', 'working', 'stale', 'missing'] as $field) {
            if (!isset($cronjobs[$field]) || !is_numeric($cronjobs[$field])) {
                return [
                    'visible' => true,
                    'level' => 'alarm',
                    'count' => '!',
                    'tooltip' => 'Cronjob data unavailable',
                ];
            }
        }

        $total = (int) $cronjobs['total'];
        $working = (int) $cronjobs['working'];
        $stale = (int) $cronjobs['stale'];
        $missing = (int) $cronjobs['missing'];

        if ($total === 0) {
            return [
                'visible' => false,
                'level' => '',
                'count' => '',
                'tooltip' => '',
            ];
        }

        $failed = $total - $working;
        $level = $this->eventManager->getServerDataThresholdLevel($failed, 'cronjobs_failed_count');
        $this->evaluateCronjobAlarm($failed, $total, $stale, $missing, $level, $server);

        $tooltip = "<strong>Working:</strong> {$working}";
        if ($stale > 0) {
            $tooltip .= "<br><strong>Stale:</strong> {$stale}";
        }
        if ($missing > 0) {
            $tooltip .= "<br><strong>Missing:</strong> {$missing}";
        }

        return [
            'visible' => true,
            'level' => $level,
            'count' => "{$working}/{$total}",
            'tooltip' => $tooltip,
        ];
    }

    /**
     * Evaluate CPU usage alarm conditions.
     */
    private function evaluateCpuUsageAlarm(int $cpuUsage, string $level, string $server): void
    {
        if ($level !== 'alarm') {
            return;
        }

        $this->eventManager->handle(new EventCommand(
            type: 'cpu_usage_too_high',
            message: "CPU usage critical ({$cpuUsage} %) on Server: {$server}",
            level: 'critical',
            server: $server
        ));
    }

    /**
     * Evaluate RAM usage alarm conditions.
     */
    private function evaluateRamUsageAlarm(int $ramUsagePercent, string $level, string $server): void
    {
        if ($level !== 'alarm') {
            return;
        }

        $this->eventManager->handle(new EventCommand(
            type: 'ram_usage_too_high',
            message: "RAM usage critical ({$ramUsagePercent} %) on Server: {$server}",
            level: 'critical',
            server: $server
        ));
    }

    /**
     * Evaluate cronjob alarm conditions.
     */
    private function evaluateCronjobAlarm(
        int $failed,
        int $total,
        int $stale,
        int $missing,
        string $level,
        string $server
    ): void {
        if ($level !== 'alarm') {
            return;
        }

        $this->eventManager->handle(new EventCommand(
            type: 'cronjobs_failing',
            message: "Cronjobs failing ({$failed}/{$total}, stale: {$stale}, missing: {$missing}) on Server: {$server}",
            level: 'critical',
            server: $server,
            metric: new EventMetric(
                'cronjobs_failed_count',
                $failed,
                $this->eventManager->getServerDataThresholdLimit('cronjobs_failed_count'),
                ['total' => $total, 'stale' => $stale, 'missing' => $missing]
            )
        ));
    }

    /**
     * Evaluate file count alarm conditions.
     *
     * @param array<string,mixed> $serverData Server data array
     * @param string              $level      Threshold level
     * @param string              $server     Server name
     */
    private function evaluateFilecountAlarm(array $serverData, string $level, string $server): void
    {
        if ($level !== 'alarm') {
            return;
        }

        $fileCount = number_format($serverData['total_system_filecount'], 0, '', '.');
        $this->eventManager->handle(new EventCommand(
            type: 'filecount_too_large',
            message: "Filecount ({$fileCount}) reached critical level on Server: {$server}",
            level: 'critical',
            server: $server
        ));
    }

    /**
     * Evaluate disk usage alarm conditions.
     *
     * @param float  $diskSpaceUsed Disk space usage percentage
     * @param string $level         Threshold level
     * @param string $server        Server name
     */
    private function evaluateDiskUsageAlarm(float $diskSpaceUsed, string $level, string $server): void
    {
        if ($level !== 'alarm') {
            return;
        }

        $this->eventManager->handle(new EventCommand(
            type: 'remaining_diskspace_too_low',
            message: "Remaining diskspace is getting too low ({$diskSpaceUsed} % used) on Server: {$server}",
            level: 'critical',
            server: $server,
            metric: new EventMetric(
                'disk_space_usage_percent',
                round($diskSpaceUsed, 2),
                $this->eventManager->getServerDataThresholdLimit('disk_space_usage_percent')
            )
        ));
    }
}
