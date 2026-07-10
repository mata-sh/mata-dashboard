<?php

declare(strict_types=1);

namespace Mata\Services\Monitor;

use Exception;
use Mata\Services\Collect\ServerDataCollectorInterface;
use Mata\Services\Event\EventManager;
use Monolog\Logger;

/**
 * Server monitoring and threshold evaluation service.
 *
 * Handles:
 * - Server system data retrieval and caching
 * - Threshold evaluation for system metrics
 * - Alert triggering for critical conditions
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
     * @param string                       $cacheType           Cache type key for TTL lookup
     *
     * @return array<string,mixed> Server metrics with threshold levels and cache metadata
     *
     * @throws Exception If server data cannot be retrieved
     */
    public function collectServerHealthData(
        ServerDataCollectorInterface $serverDataCollector,
        string $server,
        string $cacheType = 'serverData'
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

        // Calculate disk usage percentage
        $diskSpaceUsed = $serverData['disk_space_capacity'] != 0
        ? ($serverData['disk_space_used'] / $serverData['disk_space_capacity'] * 100)
        : 0;

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

        $cronjobsData = $this->buildCronjobsData($serverData);

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
                'total_system_filecount' => $filecountLevel,
                'disk_space_usage_percent' => $diskUsageLevel,
            ],
        ];
    }

    /**
     * Build cronjobs badge view data.
     *
     * @param array<string,mixed> $serverData Server data array
     *
     * @return array{visible:bool,level:string,count:string,tooltip:string}
     */
    private function buildCronjobsData(array $serverData): array
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
        if ($failed === 0) {
            $percent = 0;
        } elseif ($working === 0) {
            $percent = 100;
        } else {
            $percent = max(1, min(99, (int) round($failed / $total * 100)));
        }

        $level = $this->eventManager->getServerDataThresholdLevel($percent, 'cronjobs_failed_percent');
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
        $this->eventManager->handleEvent(
            type: 'filecount_too_large',
            message: "Filecount ({$fileCount}) reached critical level on Server: {$server}",
            level: 'critical',
            server: $server
        );
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

        $this->eventManager->handleEvent(
            type: 'remaining_diskspace_too_low',
            message: "Remaining diskspace is getting too low ({$diskSpaceUsed} % used) on Server: {$server}",
            level: 'critical',
            server: $server
        );
    }
}
