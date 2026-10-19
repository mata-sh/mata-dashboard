#!/usr/bin/php8.2
<?php

declare(strict_types=1);

use Mata\Bootstrap\CronRuntime;
use Mata\Bootstrap\MonitoringRuntime;
use Mata\Services\Cache\ApiCacheKeyFactory;
use Mata\Services\Cache\CacheTtlResolver;
use Mata\Services\Cache\FileCacheStore;
use Mata\Services\Collect\NodeResponseNormalizer;
use Mata\Services\Cron\ServerDataFetchRunner;
use Mata\Services\Cron\ServerDataFetchStats;
use Mata\Services\Monitor\Dto\AppReachabilityPolicy;
use Mata\Services\Monitor\HttpProbeClient;
use Mata\Services\Monitor\LogSizeEvaluator;
use Mata\Services\Monitor\MetricThresholds;
use Mata\Services\Monitor\ServerMonitoringFactory;
use Mata\Services\Monitor\UsersFileMonitor;
use Mata\Services\Sync\AppInventorySync;
use Mata\Utils\UsersFilePathResolver;

require_once __DIR__ . '/../vendor/autoload.php';

$runtime = CronRuntime::boot(__FILE__);
$runtime->requireDatabase();
$monitoringRuntime = MonitoringRuntime::build(
    db: $runtime->db,
    log: $runtime->log,
    paths: $runtime->paths,
    appConfig: $runtime->appConfig,
);

$paths = $runtime->paths;
$log = $runtime->log;
$cron = $runtime->cron;
$appConfig = $runtime->appConfig;
$tools = $runtime->tools;
$configSyncRepository = $monitoringRuntime->configSyncRepository;
$fatalStateRepository = $monitoringRuntime->fatalStateRepository;
$idResolver = $monitoringRuntime->idResolver;
$eventManager = $monitoringRuntime->eventManager;
$monitoringConfig = $monitoringRuntime->monitoringConfig;

if (!$cron->acquireLock()) {
    exit("Already running.\n");
}

$cron->startTimer();
$cron->log('Started');

try {
    $appInventorySync = new AppInventorySync($configSyncRepository, $idResolver, $log);
    $cacheStore = new FileCacheStore($log, $paths->tempPath . '/api-cache');
    $ttlResolver = new CacheTtlResolver($monitoringConfig->ttl);
    $eventMonitor = new LogSizeEvaluator($tools, MetricThresholds::fromConfig($monitoringConfig->thresholds));
    $serverMonitoringFactory = new ServerMonitoringFactory(
        $log,
        $cacheStore,
        new ApiCacheKeyFactory(),
        $ttlResolver,
        $eventManager,
        new NodeResponseNormalizer($log),
        new HttpProbeClient(),
        AppReachabilityPolicy::fromMonitoringConfig($monitoringConfig),
        $idResolver,
        $fatalStateRepository,
        $eventMonitor,
        $appConfig->demo,
    );
    $dataFetchRunner = new ServerDataFetchRunner($log);
    $stats = new ServerDataFetchStats();

    $apiTimeoutSeconds = $appConfig->nodeApi->timeoutSeconds;

    foreach ($monitoringConfig->servers->values as $server => $data) {
        $stats->recordConfigured();
        $cron->touchHeartbeat();

        try {
            $services = $appConfig->environment->env === 'demo'
                ? $serverMonitoringFactory->createDemo($server, $data)
                : $serverMonitoringFactory->createLive($server, $data, apiTimeoutSeconds: $apiTimeoutSeconds);

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

    try {
        $usersFileMonitor = new UsersFileMonitor(
            UsersFilePathResolver::resolve($paths),
            $paths->tempPath . '/users-file-monitor.json',
            $eventManager,
            $log,
        );
        $usersFileMonitor->check(time());
    } catch (Throwable $e) {
        $log->error('Users file monitor: ' . $e->getMessage());
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
