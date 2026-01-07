#!/usr/bin/php8.2
<?php

declare(strict_types=1);

use Watcher\Config\AlertLimitsLoader;
use Watcher\Config\MonitoringSettingsLoader;
use Watcher\Models\AlertQueueRepository;
use Watcher\Models\ConfigSyncRepository;
use Watcher\Models\EventRepository;
use Watcher\Services\Collect\ServerDataCollector;
use Watcher\Services\Foundation\AlertManager;
use Watcher\Services\Foundation\ApiClient;
use Watcher\Services\Foundation\CacheManager;
use Watcher\Services\Foundation\DataTransformers\ApiResponseTransformer;
use Watcher\Services\Foundation\EventAggregatorService;
use Watcher\Services\Foundation\EventManager;
use Watcher\Services\Foundation\EventRecorderService;
use Watcher\Services\Foundation\IdResolver;
use Watcher\Utils\ChannelLockManager;
use Watcher\Utils\TypeConverter;

extract(require_once __DIR__ . '/bootstrap.php');

if (!$cron->acquireLock()) {
    exit("Already running.\n");
}

$cron->startTimer();
$cron->touchHeartbeat();
$cron->log('Started');

try {
    // ************************************************************************************************
    // CRONJOB LOGIC
    // ************************************************************************************************

    // --- Repositories ---
    $alertQueueRepository = new AlertQueueRepository($db);
    $configSyncRepository = new ConfigSyncRepository($db);

    // --- Limits & Locking ---
    $lockManager = new ChannelLockManager($paths->tempPath . '/alert_locks', $log);
    $limitsLoader = new AlertLimitsLoader($log);
    $alertLimits = $limitsLoader->load($paths->appPath . '/config/limits.json');

    // --- Managers ---
    $alertManager = new AlertManager(
        $alertQueueRepository,
        $alertLimits,
        $lockManager,
        $log
    );

    // --- Resolvers & Events ---
    $idResolver = new IdResolver($configSyncRepository);

    $eventManager = new EventManager($alertManager, $appConfig)
        ->setEventRecorder(new EventRecorderService($db, $idResolver))
        ->setEventAggregator(new EventAggregatorService(new EventRepository($db), $idResolver));

    // --- Monitoring settings ---
    $monitoringSettingsLoader = new MonitoringSettingsLoader($eventManager->recorder, $alertManager);
    $monitoringSettings = $monitoringSettingsLoader->loadAllConfigs($paths->appPath . '/config');
    $eventManager->setMonitoringSettings($monitoringSettings);

    // --- Final sync after configs are known ---
    $idResolver->refreshFromRepository($monitoringSettings['servers'] ?? []);
    $dataTransformer = new ApiResponseTransformer($log);
    $cacheManager = new CacheManager($log);

    foreach ($monitoringSettings['servers'] as $server => $data) {
        try {
            $token = $tools->encrypt($data['api_key'] . '|' . time(), $appConfig->secrets->watcherEncryptionSecret);

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
} catch (Throwable $e) {
    $cron->log('ERROR: ' . $e->getMessage());
} finally {
    $cron->releaseLock();
}
