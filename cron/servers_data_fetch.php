#!/usr/bin/php8.2
<?php

declare(strict_types=1);

use Mata\Services\Cache\ApiCacheKeyFactory;
use Mata\Services\Cache\CacheTtlResolver;
use Mata\Services\Cache\FileCacheStore;
use Mata\Services\Cron\ServerDataFetchRunner;
use Mata\Services\Cron\ServerDataFetchStats;
use Mata\Services\DataTransformers\ApiResponseTransformer;
use Mata\Services\Foundation\LogSizeEvaluator;
use Mata\Services\Foundation\ProbeClient;
use Mata\Services\Monitor\Dto\MonitoringPolicy;
use Mata\Services\Monitor\ServerMonitoringFactory;
use Mata\Services\Sync\AppInventorySync;

$requiresDatabase = true;

extract(require_once __DIR__ . '/bootstrap.php');
extract(require_once __DIR__ . '/bootstrap_events.php');

if (!$cron->acquireLock()) {
    exit("Already running.\n");
}

$cron->startTimer();
$cron->log('Started');

try {
    $appInventorySync = new AppInventorySync($configSyncRepository, $idResolver, $log);
    $cacheStore = new FileCacheStore($log, $paths->tempPath . '/api-cache');
    $ttlResolver = new CacheTtlResolver($monitoringSettings['ttl'] ?? []);
    $eventMonitor = new LogSizeEvaluator($tools, $eventManager);
    $serverMonitoringFactory = new ServerMonitoringFactory(
        $log,
        $cacheStore,
        new ApiCacheKeyFactory(),
        $ttlResolver,
        $eventManager,
        new ApiResponseTransformer($log),
        new ProbeClient(),
        MonitoringPolicy::fromSettings($monitoringSettings),
        $idResolver,
        $fatalStateRepository,
        $eventMonitor,
    );
    $dataFetchRunner = new ServerDataFetchRunner($log);
    $stats = new ServerDataFetchStats();

    foreach ($monitoringSettings['servers'] as $server => $data) {
        $stats->recordConfigured();
        $cron->touchHeartbeat();

        try {
            $services = $appConfig->environment->env === 'demo'
                ? $serverMonitoringFactory->createDemo($server, $data)
                : $serverMonitoringFactory->createLive($server, $data, apiTimeoutSeconds: 30);

            $result = $dataFetchRunner->run(
                $server,
                $services->collector,
                $services->reachabilityMonitor,
                $services->errorMonitor,
                $services->healthMonitor,
                $appInventorySync,
            );
            $stats->recordResult($result);
        } catch (Throwable $e) {
            $log->error("{$server}: " . $e->getMessage());
            $stats->recordFailure();
        }
    }

    foreach ($stats->summaryLines($cron->endTimer(), $cron->memoryUsage()) as $line) {
        $cron->log($line);
    }
    $cron->touchHeartbeat();
} catch (Throwable $e) {
    $cron->log('ERROR: ' . $e->getMessage());
} finally {
    $cron->releaseLock();
}
