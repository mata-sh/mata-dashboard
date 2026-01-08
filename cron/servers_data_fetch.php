#!/usr/bin/php8.2
<?php

declare(strict_types=1);

use Watcher\Services\Collect\ServerDataCollector;
use Watcher\Services\Foundation\ApiClient;
use Watcher\Services\Foundation\CacheManager;
use Watcher\Services\Foundation\DataTransformers\ApiResponseTransformer;
use Watcher\Utils\TypeConverter;

$requiresDatabase = true;

extract(require_once __DIR__ . '/bootstrap.php');
extract(require_once __DIR__ . '/bootstrap_events.php');

if (!$cron->acquireLock()) {
    exit("Already running.\n");
}

$cron->startTimer();
$cron->log('Started');

try {
    // All event/alert infrastructure now loaded via bootstrap_events.php
    $dataTransformer = new ApiResponseTransformer($log);
    $cacheManager = new CacheManager($log);

    foreach ($monitoringSettings['servers'] as $server => $data) {
        try {
            $token = $tools->buildHmacAuthToken($server, $data['api_key'], time());

            // Extract security flags with defaults
            $allowHttp = TypeConverter::toBool($data['allow_http'] ?? null) ?? false;
            $suppressHttpWarning = TypeConverter::toBool($data['suppress_http_warning'] ?? null) ?? false;

            $apiClient = new ApiClient($log, $data['api_url'], $token, 10, true, $allowHttp, $suppressHttpWarning);
            $serversApi[$server] = new ServerDataCollector($log, $cacheManager, $apiClient, $dataTransformer);
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
