#!/usr/bin/php8.2
<?php

declare(strict_types=1);

use Mata\Services\Cache\ApiCacheKeyFactory;
use Mata\Services\Cache\CacheTtlResolver;
use Mata\Services\Cache\FileCacheStore;
use Mata\Services\Collect\ServerDataCollector;
use Mata\Services\Collect\ServerDataCollectorInterface;
use Mata\Services\DataTransformers\ApiResponseTransformer;
use Mata\Services\Foundation\ApiClient;
use Mata\Services\Foundation\ProbeClient;
use Mata\Services\Monitor\AppMonitor;
use Mata\Services\Monitor\AppMonitorInterface;
use Mata\Services\Monitor\Dto\EndpointTarget;
use Mata\Services\Monitor\NodeHealthMonitor;
use Mata\Services\Monitor\NodeHealthMonitorInterface;
use Mata\Utils\TypeConverter;
use Psr\Log\LoggerInterface;

$requiresDatabase = true;

extract(require_once __DIR__ . '/bootstrap.php');
extract(require_once __DIR__ . '/bootstrap_events.php');

if (!$cron->acquireLock()) {
    exit("Already running.\n");
}

$cron->startTimer();
$cron->log('Started');

try {
    // All event/alarm infrastructure now loaded via bootstrap_events.php
    $dataTransformer = new ApiResponseTransformer($log);
    // Must be the directory the dashboard reads (see public/index.php), otherwise nothing is warmed
    $cacheStore = new FileCacheStore($log, $paths->tempPath . '/api-cache');
    $cacheKeyFactory = new ApiCacheKeyFactory();
    $ttlResolver = new CacheTtlResolver($monitoringSettings['ttl'] ?? []);
    $probeClient = new ProbeClient();

    $stats = [
        'configured' => 0,
        'warmed' => 0,
        'failed' => 0,
    ];

    foreach ($monitoringSettings['servers'] as $server => $data) {
        ++$stats['configured'];
        $cron->touchHeartbeat();

        try {
            // Extract security flags with defaults
            $allowHttp = TypeConverter::toBool($data['allow_http'] ?? null) ?? false;
            $suppressHttpWarning = TypeConverter::toBool($data['suppress_http_warning'] ?? null) ?? false;

            // Background refresh tolerates slower nodes than the dashboard's 10s;
            // API cache keys do not include the timeout, so the same entries are warmed
            $apiClient = new ApiClient(
                $log,
                $data['api_url'],
                $server,
                $data['api_key'],
                30,
                true,
                $allowHttp,
                $suppressHttpWarning
            );
            $collector = new ServerDataCollector(
                $log,
                $cacheStore,
                $cacheKeyFactory,
                $ttlResolver,
                $apiClient,
                $dataTransformer
            );
            $appMonitor = new AppMonitor(
                $cacheStore,
                $cacheKeyFactory,
                $ttlResolver,
                $apiClient,
                $probeClient,
                $eventManager,
                $log
            );
            $healthMonitor = new NodeHealthMonitor(
                $cacheStore,
                $cacheKeyFactory,
                $ttlResolver,
                $apiClient,
                $probeClient,
                $eventManager,
                $log
            );

            warmServerCaches($server, $collector, $appMonitor, $healthMonitor, $log);
            ++$stats['warmed'];
        } catch (Throwable $e) {
            $log->error("{$server}: " . $e->getMessage());
            ++$stats['failed'];
        }
    }

    $cron->log(sprintf(
        'Finished in %.3fs (memory: %s) | servers=%d warmed=%d failed=%d',
        $cron->endTimer(),
        $cron->memoryUsage(),
        $stats['configured'],
        $stats['warmed'],
        $stats['failed']
    ));
    $cron->touchHeartbeat();

} catch (Throwable $e) {
    $cron->log('ERROR: ' . $e->getMessage());
} finally {
    $cron->releaseLock();
}

/**
 * Warm every cache entry a dashboard server card reads, one server at a time.
 *
 * Uses the same collectors and cache keys as page loads, so entries still
 * fresh within their TTL short-circuit and the node is not contacted twice.
 * Servers are processed strictly sequentially to keep the load on shared
 * hosts low; only the availability probes run concurrently, capped by
 * AppMonitor::MAX_CONCURRENT_PROBES.
 */
function warmServerCaches(
    string $server,
    ServerDataCollectorInterface $collector,
    AppMonitorInterface $appMonitor,
    NodeHealthMonitorInterface $healthMonitor,
    LoggerInterface $log
): void {
    $healthMonitor->checkOrWait($server);
    $collector->getServerSystemData();

    $appsResponse = $collector->getApps();
    if ($appsResponse === null || !is_array($appsResponse['result'] ?? null)) {
        $log->warning("{$server}: apps could not be fetched, skipping availability probes");

        return;
    }

    // Same ping filter and probe defaults (timeout, cache type) as ServerViewBuilder;
    // the probe timeout is part of the availability cache key
    $pingableApps = array_filter(
        $appsResponse['result'],
        static fn ($app): bool => is_array($app) && ($app['ping'] ?? 'on') !== 'off'
    );
    $targets = EndpointTarget::fromRawAppData($pingableApps, $server);
    if ($targets !== []) {
        $appMonitor->checkMultipleEndpoints($targets);
    }
}
