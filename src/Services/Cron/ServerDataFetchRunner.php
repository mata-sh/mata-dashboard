<?php

declare(strict_types=1);

namespace Mata\Services\Cron;

use Mata\Services\Collect\ServerDataCollectorInterface;
use Mata\Services\Cron\Dto\ServerDataFetchResult;
use Mata\Services\Monitor\AppErrorMonitorInterface;
use Mata\Services\Monitor\AppReachabilityMonitorInterface;
use Mata\Services\Monitor\Dto\AppErrorMonitoringResult;
use Mata\Services\Monitor\Dto\EndpointTarget;
use Mata\Services\Monitor\Dto\NodeHealthState;
use Mata\Services\Monitor\NodeHealthMonitorInterface;
use Mata\Services\Sync\AppInventorySync;
use Psr\Log\LoggerInterface;
use Throwable;

/** Runs one server data fetch from a single forced /apps response. */
final class ServerDataFetchRunner
{
    public function __construct(private LoggerInterface $logger) {}

    public function run(
        string $server,
        ServerDataCollectorInterface $collector,
        AppReachabilityMonitorInterface $reachabilityMonitor,
        AppErrorMonitorInterface $errorMonitor,
        NodeHealthMonitorInterface $healthMonitor,
        AppInventorySync $appInventorySync,
    ): ServerDataFetchResult {
        // The health probe runs first, so an unreachable node is already known here.
        $health = $healthMonitor->checkSynchronously($server, forceRefresh: true);
        if ($health->status === NodeHealthState::Unreachable) {
            $this->logger->warning("{$server}: node unreachable, skipping remaining data fetch for this cycle", [
                'failure_kind' => $health->failureKind,
                'attempts' => $health->attempts,
            ]);

            return ServerDataFetchResult::nodeUnreachable();
        }

        $collector->getServerSystemData(forceRefresh: true);
        $appsResponse = $collector->getApps(forceRefresh: true);
        if ($appsResponse === null || !is_array($appsResponse['result'] ?? null)) {
            $this->logger->warning("{$server}: apps could not be fetched, skipping application monitoring and availability probes");

            return new ServerDataFetchResult(false, 0, 0, AppErrorMonitoringResult::empty());
        }

        $apps = $appsResponse['result'];
        $synced = $appInventorySync->sync($apps);

        try {
            $monitoring = $errorMonitor->monitor($server, $apps);
        } catch (Throwable $exception) {
            $this->logger->warning("{$server}: application error monitoring failed", ['exception' => $exception]);
            $monitoring = AppErrorMonitoringResult::empty(count($apps), failures: 1);
        }

        // EndpointTarget owns reachability filtering.
        $targets = EndpointTarget::fromRawAppData($apps, $server);
        if ($targets !== []) {
            $reachabilityMonitor->checkMultipleEndpoints($targets, forceRefresh: true);
        }

        return new ServerDataFetchResult(true, $synced, count($targets), $monitoring);
    }
}
