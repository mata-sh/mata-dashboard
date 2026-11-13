#!/usr/bin/env php
<?php

declare(strict_types=1);

use Mata\Bootstrap\CronRuntime;
use Mata\Bootstrap\MonitoringRuntime;
use Mata\Models\Monitor\AppLibraryAuditStateRepository;
use Mata\Services\Cache\ApiCacheKeyFactory;
use Mata\Services\Cache\CacheTtlResolver;
use Mata\Services\Cache\FileCacheStore;
use Mata\Services\Collect\NodeApiClient;
use Mata\Services\Collect\NodeResponseNormalizer;
use Mata\Services\Collect\ServerDataCollector;
use Mata\Services\Monitor\AppLibraryAuditMonitor;
use Mata\Services\Monitor\Dto\AppLibraryAuditMonitoringResult;

require_once __DIR__ . '/../vendor/autoload.php';

$runtime = CronRuntime::boot(__FILE__);
$runtime->requireDatabase();
$monitoringRuntime = MonitoringRuntime::build(
    paths: $runtime->paths,
    appConfig: $runtime->appConfig,
    log: $runtime->log,
    db: $runtime->db,
);

$paths = $runtime->paths;
$log = $runtime->log;
$cron = $runtime->cron;
$appConfig = $runtime->appConfig;
$monitoringConfig = $monitoringRuntime->monitoringConfig;

if (!$cron->acquireLock()) {
    exit("Already running.\n");
}

$failed = false;
$cron->startTimer();
$cron->log('Started');

try {
    if ($appConfig->environment->isDemo()) {
        $cron->log('Demo environment: library audit monitoring skipped');
        $cron->touchHeartbeat();

        return;
    }

    // Audits go through the shared api-cache: the node only runs `composer audit` when an
    // app's cached audit is older than the audit TTL, and manual dashboard runs count too.
    $cacheStore = new FileCacheStore($log, $paths->apiCachePath);
    $cacheKeyFactory = new ApiCacheKeyFactory();
    $ttlResolver = new CacheTtlResolver($monitoringConfig->ttl);
    $responseNormalizer = new NodeResponseNormalizer($log);
    $monitor = new AppLibraryAuditMonitor(
        $monitoringRuntime->eventManager,
        $monitoringRuntime->idResolver,
        new AppLibraryAuditStateRepository($runtime->db),
        $log,
    );
    $result = new AppLibraryAuditMonitoringResult();

    // ServersConfig holds enabled servers only.
    foreach ($monitoringConfig->servers->values as $server => $serverConfig) {
        $server = (string) $server;
        if (str_starts_with($server, '_')) {
            continue;
        }

        $cron->touchHeartbeat();
        $apiUrl = $serverConfig->apiUrl;
        $apiKey = $serverConfig->apiKey;
        if ($apiUrl === '' || $apiKey === '') {
            $log->error('libraries_audit.missing_api_credentials', ['server' => $server]);
            $result = $result->mergedWith(new AppLibraryAuditMonitoringResult(failures: 1));

            continue;
        }

        try {
            $collector = new ServerDataCollector(
                $log,
                $cacheStore,
                $cacheKeyFactory,
                $ttlResolver,
                new NodeApiClient(
                    logger: $log,
                    apiBase: $apiUrl,
                    server: $server,
                    apiKey: $apiKey,
                    timeout: $appConfig->nodeApi->timeoutSeconds,
                    verifySSL: true,
                    allowHttp: $serverConfig->allowHttp,
                    suppressHttpWarning: $serverConfig->suppressHttpWarning,
                ),
                $responseNormalizer,
            );
            $serverResult = $monitor->monitor($server, $collector);
            $cron->log("{$server}: " . $serverResult->summaryLine());
            $result = $result->mergedWith($serverResult);
        } catch (Throwable $exception) {
            $log->error('libraries_audit.server_failed', ['server' => $server, 'exception' => $exception]);
            $result = $result->mergedWith(new AppLibraryAuditMonitoringResult(failures: 1));
        }
    }

    $duration = $cron->endTimer();
    $cron->log(sprintf('Finished in %.3fs (memory: %s) | %s', $duration, $cron->memoryUsage(), $result->summaryLine()));
    $cron->touchHeartbeat();
} catch (Throwable $exception) {
    $failed = true;
    $cron->log('ERROR: ' . $exception->getMessage());
    $log->critical('libraries_audit.unhandled_exception', ['exception' => $exception]);
} finally {
    $cron->releaseLock();
}

if ($failed) {
    exit(1);
}
