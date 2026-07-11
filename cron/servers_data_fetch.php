#!/usr/bin/php8.2
<?php

declare(strict_types=1);

use Mata\Services\Cache\ApiCacheKeyFactory;
use Mata\Services\Cache\CacheTtlResolver;
use Mata\Services\Cache\FileCacheStore;
use Mata\Services\Collect\ServerDataCollector;
use Mata\Services\DataTransformers\ApiResponseTransformer;
use Mata\Services\Foundation\ApiClient;
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
    $dataTransformer = new ApiResponseTransformer($log);
    $cacheStore = new FileCacheStore($log);
    $cacheKeyFactory = new ApiCacheKeyFactory();
    $ttlResolver = new CacheTtlResolver($monitoringSettings['ttl'] ?? []);

    foreach ($monitoringSettings['servers'] as $server => $data) {
        try {
            $token = $tools->buildHmacAuthToken($server, $data['api_key'], time());

            // Extract security flags with defaults
            $allowHttp = TypeConverter::toBool($data['allow_http'] ?? null) ?? false;
            $suppressHttpWarning = TypeConverter::toBool($data['suppress_http_warning'] ?? null) ?? false;

            $apiClient = new ApiClient($log, $data['api_url'], $token, 10, true, $allowHttp, $suppressHttpWarning);
            $serversApi[$server] = new ServerDataCollector(
                $log,
                $cacheStore,
                $cacheKeyFactory,
                $ttlResolver,
                $apiClient,
                $dataTransformer
            );
        } catch (Exception $e) {
            $log->error("{$server}: " . $e->getMessage());
        }
    }

    $cron->log("Finished in {$cron->endTimer()}s, memory: {$cron->memoryUsage()}");
    $cron->touchHeartbeat();

} catch (Throwable $e) {
    $cron->log('ERROR: ' . $e->getMessage());
} finally {
    $cron->releaseLock();
}
