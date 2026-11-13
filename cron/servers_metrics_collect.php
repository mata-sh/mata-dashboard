#!/usr/bin/env php
<?php

declare(strict_types=1);

use Mata\Bootstrap\CronRuntime;
use Mata\Bootstrap\MonitoringRuntime;
use Mata\Models\ServerMetrics\ServerMetricsRepository;
use Mata\Services\Cache\ApiCacheKeyFactory;
use Mata\Services\Cache\CacheTtlResolver;
use Mata\Services\Cache\FileCacheStore;
use Mata\Services\Collect\NodeApiClient;
use Mata\Services\Collect\NodeResponseNormalizer;
use Mata\Services\Collect\ServerDataCollector;
use Mata\Services\Monitor\MetricThresholds;
use Mata\Services\Monitor\ServerMetricsHealthMonitor;
use Mata\Services\Monitor\ServerMonitor;
use Mata\Services\ServerMetrics\ServerMetricSnapshotFactory;

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
$db = $runtime->db;
$configSyncRepository = $monitoringRuntime->configSyncRepository;
$idResolver = $monitoringRuntime->idResolver;
$eventManager = $monitoringRuntime->eventManager;
$monitoringConfig = $monitoringRuntime->monitoringConfig;

if (!$cron->acquireLock()) {
    exit("Already running.\n");
}

$cron->startTimer();
$cron->log('Started');

try {
    // Monitoring services come from MonitoringRuntime::build()
    $dataRetentionConfig = $monitoringConfig->dataRetention;

    // Share the dashboard/servers_data_fetch cache so a server/data payload warmed
    // within its TTL is reused instead of re-fetched from the node
    $cacheStore = new FileCacheStore($log, $paths->apiCachePath);
    $cacheKeyFactory = new ApiCacheKeyFactory();
    $ttlResolver = new CacheTtlResolver($monitoringConfig->ttl);
    $responseNormalizer = new NodeResponseNormalizer($log);
    $repository = new ServerMetricsRepository($db, $idResolver, $configSyncRepository);
    $snapshotFactory = new ServerMetricSnapshotFactory();
    $serverMonitor = new ServerMonitor($log, $eventManager, MetricThresholds::fromConfig($monitoringConfig->thresholds));

    $ingestionHealth = $dataRetentionConfig->ingestionHealth;
    $stallThresholdMinutes = $ingestionHealth['stall_minutes'];
    $healthLogSizeLimit = $ingestionHealth['log_rotate_megabytes'] * 1024 * 1024;
    $healthLogRetainedFiles = $ingestionHealth['log_rotate_files'];

    $healthMonitor = new ServerMetricsHealthMonitor(
        repository: $repository,
        eventManager: $eventManager,
        logger: $log,
        healthLogPath: $paths->logsPath . '/health/collect_server_metrics_health.log',
        stallThresholdMinutes: $stallThresholdMinutes,
        maxLogBytes: $healthLogSizeLimit,
        retainedFiles: $healthLogRetainedFiles
    );
    $healthMonitor->rotateLog();
    $baseline = $healthMonitor->snapshot();

    $stats = [
        'configured' => 0,
        'skipped' => 0,
        'stored' => 0,
        'failed' => 0,
    ];
    $activeServers = [];

    // ServersConfig holds enabled servers only.
    foreach ($monitoringConfig->servers->values as $serverName => $serverConfig) {
        if (str_starts_with((string) $serverName, '_')) {
            ++$stats['skipped'];

            continue;
        }

        ++$stats['configured'];
        $cron->touchHeartbeat();
        $activeServers[(string) $serverName] = true;

        $apiUrl = $serverConfig->apiUrl;
        $apiKey = $serverConfig->apiKey;

        if ($apiUrl === '' || $apiKey === '') {
            $log->error('collect_server_metrics.missing_api_credentials', [
                'server' => $serverName,
            ]);
            ++$stats['failed'];

            continue;
        }

        try {
            $apiClient = new NodeApiClient(
                $log,
                $apiUrl,
                (string) $serverName,
                $apiKey,
                10,
                true,
                $serverConfig->allowHttp,
                $serverConfig->suppressHttpWarning
            );
            $collector = new ServerDataCollector(
                $log,
                $cacheStore,
                $cacheKeyFactory,
                $ttlResolver,
                $apiClient,
                $responseNormalizer
            );

            try {
                $serverMonitor->evaluateDatabaseOverview($collector, (string) $serverName);
            } catch (Throwable $databaseError) {
                $log->error('collect_server_metrics.database_failed', [
                    'server' => $serverName,
                    'error' => $databaseError->getMessage(),
                ]);
            }

            $metricsPayload = $serverMonitor->collectServerHealthData($collector, (string) $serverName);

            $snapshot = $snapshotFactory->fromHealthPayload($metricsPayload);
            $collector = $snapshotFactory->collectorIdentity($metricsPayload, $serverConfig);

            $repository->storeRawSample(
                (string) $serverName,
                $collector->name,
                $snapshot,
                $collector->version,
                $collector->contact
            );

            ++$stats['stored'];

            $log->info('collect_server_metrics.stored', [
                'server' => $serverName,
                'collector' => $collector->name,
                'collected_at' => $snapshot->collectedAt->format(DateTimeImmutable::ATOM),
                'cache_age' => $metricsPayload['cache']['age'] ?? null,
                'cache_ttl' => $metricsPayload['cache']['ttl'] ?? null,
                'from_cache' => $metricsPayload['cache']['from_cache'] ?? false,
            ]);
        } catch (Throwable $collectionError) {
            $log->error('collect_server_metrics.failed', [
                'server' => $serverName,
                'error' => $collectionError->getMessage(),
            ]);
            ++$stats['failed'];
        }
    }

    $runCompletedAt = new DateTimeImmutable();
    $ingestionReport = $healthMonitor->evaluate(array_keys($activeServers), $baseline, $runCompletedAt);

    $duration = $cron->endTimer();
    $summary = sprintf(
        'Finished in %.3fs (memory: %s) | servers=%d stored=%d skipped=%d failed=%d inserted=%d stalled=%d',
        $duration,
        $cron->memoryUsage(),
        $stats['configured'],
        $stats['stored'],
        $stats['skipped'],
        $stats['failed'],
        $ingestionReport['total_inserted'],
        count($ingestionReport['stalled'])
    );

    $cron->log($summary);
    $log->info('collect_server_metrics.summary', [
        'duration_seconds' => $duration,
        'memory' => $cron->memoryUsage(),
        'stats' => $stats,
        'retention' => [
            'raw_days' => $dataRetentionConfig->metrics['raw_retention_days'] ?? null,
            'hourly_lag_minutes' => $dataRetentionConfig->aggregation['hourly']['lag_minutes'] ?? null,
        ],
        'ingestion' => [
            'generated_at' => $ingestionReport['generated_at'],
            'total_inserted' => $ingestionReport['total_inserted'],
            'servers' => $ingestionReport['per_server'],
            'stalled_servers' => array_keys($ingestionReport['stalled']),
        ],
    ]);
    $cron->touchHeartbeat();

} catch (Throwable $e) {
    $cron->log('ERROR: ' . $e->getMessage());
    $log->critical('collect_server_metrics.unhandled_exception', [
        'error' => $e->getMessage(),
    ]);
} finally {
    $cron->releaseLock();
}
