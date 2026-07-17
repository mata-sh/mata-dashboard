#!/usr/bin/php8.2
<?php

declare(strict_types=1);

use Mata\Models\ServerMetrics\Dto\ServerMetricRawSnapshot;
use Mata\Models\ServerMetrics\ServerMetricsRepository;
use Mata\Services\Cache\ApiCacheKeyFactory;
use Mata\Services\Cache\CacheTtlResolver;
use Mata\Services\Cache\FileCacheStore;
use Mata\Services\Collect\ServerDataCollector;
use Mata\Services\DataTransformers\ApiResponseTransformer;
use Mata\Services\Foundation\ApiClient;
use Mata\Services\Monitor\ServerMetricsHealthMonitor;
use Mata\Services\Monitor\ServerMonitor;
use Mata\Utils\TypeConverter;

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
    $dataRetentionConfig = $monitoringSettings['data_retention'] ?? [];

    // Share the dashboard/servers_data_fetch cache so a server/data payload warmed
    // within its TTL is reused instead of re-fetched from the node
    $cacheStore = new FileCacheStore($log, $paths->tempPath . '/api-cache');
    $cacheKeyFactory = new ApiCacheKeyFactory();
    $ttlResolver = new CacheTtlResolver($monitoringSettings['ttl'] ?? []);
    $dataTransformer = new ApiResponseTransformer($log);
    $repository = new ServerMetricsRepository($db, $idResolver, $configSyncRepository);
    $serverMonitor = new ServerMonitor($log, $eventManager);

    $ingestionHealth = is_array($dataRetentionConfig['ingestion_health'] ?? null)
        ? $dataRetentionConfig['ingestion_health']
        : [];
    $stallThresholdMinutes = (int) max(5, $ingestionHealth['stall_minutes'] ?? 15);
    $healthLogSizeLimit = (int) max(1, $ingestionHealth['log_rotate_megabytes'] ?? 5) * 1024 * 1024;
    $healthLogRetainedFiles = (int) max(1, $ingestionHealth['log_rotate_files'] ?? 3);

    $healthMonitor = new ServerMetricsHealthMonitor(
        repository: $repository,
        eventManager: $eventManager,
        logger: $log,
        healthLogPath: $paths->logsPath . '/cron/collect_server_metrics_health.log',
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

    foreach ($monitoringSettings['servers'] ?? [] as $serverName => $serverConfig) {
        if (!is_array($serverConfig)) {
            continue;
        }

        if (str_starts_with((string) $serverName, '_')) {
            ++$stats['skipped'];

            continue;
        }

        if (!isServerActive($serverConfig)) {
            $log->info('collect_server_metrics.skip_inactive', [
                'server' => $serverName,
            ]);
            ++$stats['skipped'];

            continue;
        }

        ++$stats['configured'];
        $cron->touchHeartbeat();
        $activeServers[(string) $serverName] = true;

        $apiUrl = $serverConfig['api_url'] ?? null;
        $apiKey = $serverConfig['api_key'] ?? null;

        if (!is_string($apiUrl) || $apiUrl === '' || !is_string($apiKey) || $apiKey === '') {
            $log->error('collect_server_metrics.missing_api_credentials', [
                'server' => $serverName,
            ]);
            ++$stats['failed'];

            continue;
        }

        try {
            // Extract security flags with defaults
            $allowHttp = TypeConverter::toBool($serverConfig['allow_http'] ?? null) ?? false;
            $suppressHttpWarning = TypeConverter::toBool($serverConfig['suppress_http_warning'] ?? null) ?? false;

            $apiClient = new ApiClient(
                $log,
                $apiUrl,
                (string) $serverName,
                $apiKey,
                10,
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

            $metricsPayload = $serverMonitor->collectServerHealthData($collector, (string) $serverName);

            $snapshot = buildSnapshotFromPayload($metricsPayload);
            [$collectorName, $collectorVersion, $collectorContact] = resolveCollectorIdentity(
                $metricsPayload['data'] ?? [],
                $serverConfig
            );

            $repository->storeRawSample(
                (string) $serverName,
                $collectorName,
                $snapshot,
                $collectorVersion,
                $collectorContact
            );

            ++$stats['stored'];

            $log->info('collect_server_metrics.stored', [
                'server' => $serverName,
                'collector' => $collectorName,
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
            'raw_days' => $dataRetentionConfig['metrics']['raw_retention_days'] ?? null,
            'hourly_lag_minutes' => $dataRetentionConfig['aggregation']['hourly']['lag_minutes'] ?? null,
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

/**
 * @param array<string,mixed> $payload
 */
function buildSnapshotFromPayload(array $payload): ServerMetricRawSnapshot
{
    $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
    $cache = is_array($payload['cache'] ?? null) ? $payload['cache'] : [];

    $collectedAt = resolveCollectedAt($data, $cache);
    $ingestedAt = resolveIngestedAt();

    $cpuUsage = TypeConverter::toFloat($data['cpu_usage'] ?? null);

    $ramUsed = TypeConverter::toInt(
        $data['ram_used']
        ?? ($data['ram']['used'] ?? null)
        ?? ($data['memory']['used'] ?? null)
    );
    $ramTotal = TypeConverter::toInt(
        $data['ram_capacity']
        ?? ($data['ram']['total'] ?? null)
        ?? ($data['memory']['total'] ?? null)
        ?? ($data['ram_total'] ?? null)
    );

    $diskUsed = TypeConverter::toInt(
        $data['disk_space_used']
        ?? ($data['disk']['used'] ?? null)
        ?? ($data['storage']['used'] ?? null)
    );
    $diskTotal = TypeConverter::toInt(
        $data['disk_space_capacity']
        ?? ($data['disk']['total'] ?? null)
        ?? ($data['storage']['total'] ?? null)
        ?? ($data['disk_total'] ?? null)
    );

    $ramPercent = computePercent($ramUsed, $ramTotal);
    $diskPercent = computePercent($diskUsed, $diskTotal);

    $totalFileCount = TypeConverter::toInt(
        $data['total_system_filecount']
        ?? ($data['filecount']['total'] ?? null)
        ?? ($data['filesystem']['filecount'] ?? null)
    );

    $processCount = TypeConverter::toInt(
        $data['processes']['total'] ?? $data['total_processes'] ?? $data['process_count'] ?? null
    );
    $phpProcessCount = TypeConverter::toInt(
        $data['processes']['php'] ?? $data['php_process_count'] ?? null
    );

    $phpVersion = TypeConverter::toString(
        $data['php_version']
        ?? ($data['php']['version'] ?? null)
    );

    $osVersion = TypeConverter::toString(
        $data['os_version']
        ?? ($data['system']['os_version'] ?? null)
        ?? ($data['os']['version'] ?? null)
    );

    $serverUptime = TypeConverter::toInt(
        $data['server_uptime']
        ?? ($data['system']['uptime'] ?? null)
        ?? ($data['uptime'] ?? null)
    );

    return new ServerMetricRawSnapshot(
        $collectedAt,
        $ingestedAt,
        $cpuUsage,
        $ramUsed,
        $ramTotal,
        $diskUsed,
        $diskTotal,
        $ramPercent,
        $diskPercent,
        $totalFileCount,
        $processCount,
        $phpProcessCount,
        $phpVersion,
        $osVersion,
        $serverUptime
    );
}

/**
 * @param array<string,mixed> $serverData
 * @param array<string,mixed> $serverConfig
 *
 * @return array{0:string,1:?string,2:?string}
 */
function resolveCollectorIdentity(array $serverData, array $serverConfig): array
{
    $collectorData = $serverData['collector'] ?? null;
    if (is_array($collectorData)) {
        $name = TypeConverter::toString($collectorData['name'] ?? null);
        if ($name === null || $name === '') {
            $name = 'mata-node';
        }

        return [
            $name,
            TypeConverter::toString($collectorData['version'] ?? null),
            TypeConverter::toString($collectorData['contact'] ?? null),
        ];
    }

    $fallbackName = TypeConverter::toString($serverConfig['collector_name'] ?? null)
        ?? TypeConverter::toString($serverConfig['node_name'] ?? null)
        ?? 'collect_server_metrics_cron';

    return [
        $fallbackName,
        TypeConverter::toString($serverConfig['collector_version'] ?? null),
        TypeConverter::toString($serverConfig['collector_contact'] ?? null),
    ];
}

/**
 * @param array<string,mixed> $data
 * @param array<string,mixed> $cache
 */
function resolveCollectedAt(array $data, array $cache): DateTimeImmutable
{
    $candidates = [
        $data['collected_at'] ?? null,
        $data['timestamp'] ?? null,
        $data['generated_at'] ?? null,
        $data['created_at'] ?? null,
        $data['meta']['timestamp'] ?? null,
    ];

    foreach ($candidates as $candidate) {
        $parsed = parseDateTimeCandidate($candidate);
        if ($parsed !== null) {
            return $parsed;
        }
    }

    $cacheAge = isset($cache['age']) ? (int) $cache['age'] : 0;
    if ($cacheAge > 0) {
        try {
            $interval = new DateInterval('PT' . $cacheAge . 'S');

            return (new DateTimeImmutable())->sub($interval);
        } catch (Throwable) {
            // noop, fall back to now
        }
    }

    return new DateTimeImmutable('now', new DateTimeZone('UTC'));
}

function resolveIngestedAt(): DateTimeImmutable
{
    $formatted = sprintf('%.6F', microtime(true));
    $dateTime = DateTimeImmutable::createFromFormat('U.u', $formatted);

    if ($dateTime instanceof DateTimeImmutable) {
        return $dateTime->setTimezone(new DateTimeZone('UTC'));
    }

    return new DateTimeImmutable('now', new DateTimeZone('UTC'));
}

function parseDateTimeCandidate(mixed $value): ?DateTimeImmutable
{
    if ($value instanceof DateTimeImmutable) {
        return $value->setTimezone(new DateTimeZone('UTC'));
    }

    if ($value instanceof DateTimeInterface) {
        return DateTimeImmutable::createFromInterface($value)->setTimezone(new DateTimeZone('UTC'));
    }

    if (is_string($value) && $value !== '') {
        try {
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
        } catch (Throwable) {
            return null;
        }
    }

    if (is_numeric($value)) {
        $numeric = (float) $value;
        if ($numeric > 9999999999) {
            $numeric /= 1000;
        }

        $dateTime = DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $numeric));
        if ($dateTime instanceof DateTimeImmutable) {
            return $dateTime->setTimezone(new DateTimeZone('UTC'));
        }
    }

    return null;
}

function computePercent(?int $used, ?int $total): ?float
{
    if ($used === null || $total === null || $total <= 0) {
        return null;
    }

    return round(($used / $total) * 100, 2);
}

/**
 * @param array<string,mixed> $config
 */
function isServerActive(array $config): bool
{
    if ((TypeConverter::toBool($config['disabled'] ?? null) ?? false) === true) {
        return false;
    }

    foreach (['enabled', 'active', 'monitoring_enabled'] as $flag) {
        $normalized = TypeConverter::toBool($config[$flag] ?? null);
        if ($normalized === false) {
            return false;
        }
    }

    return true;
}
