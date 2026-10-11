#!/usr/bin/php8.2
<?php

declare(strict_types=1);

use Mata\Bootstrap\AlarmChannelRuntime;
use Mata\Bootstrap\CronRuntime;
use Mata\Config\Monitoring\Loader\AlarmRateLimitsConfigLoader;
use Mata\Models\Alarm\AlarmDeliveryRepository;
use Mata\Services\Alarm\Delivery\AlarmDispatcher;
use Mata\Services\Event\EventManager;
use Mata\Services\Event\EventRecorderService;

require_once __DIR__ . '/../vendor/autoload.php';

$runtime = CronRuntime::boot(__FILE__);
$runtime->requireDatabase();

$paths = $runtime->paths;
$log = $runtime->log;
$cron = $runtime->cron;
$appConfig = $runtime->appConfig;
$db = $runtime->db;

if (!$cron->acquireLock()) {
    exit("Already running.\n");
}

$cron->startTimer();
$cron->log('Started');

try {
    // --- Repositories ---
    $deliveryRepository = new AlarmDeliveryRepository($db);

    // --- Alarm Limits ---
    $eventManager = new EventManager($appConfig, eventRecorder: new EventRecorderService($db), logger: $log);
    $limitsLoader = new AlarmRateLimitsConfigLoader($eventManager);
    $limitsConfig = $limitsLoader->load($paths->configPath);

    // --- Senders & Dispatchers ---
    $alarmChannels = AlarmChannelRuntime::fromRuntime($runtime, $eventManager);
    $alarmChannels->reportUnknownLimitChannels($limitsConfig);
    $senders = $alarmChannels->senders();
    $dispatcher = new AlarmDispatcher($deliveryRepository, $limitsConfig, $senders, $log);

    // --- Dispatch Alarm Deliveries ---
    $dispatcher->dispatch(new DateTimeImmutable('now'), 100);

    $cron->log("Finished in {$cron->endTimer()}s, memory: {$cron->memoryUsage()}");
    $cron->touchHeartbeat();

} catch (Throwable $e) {
    $cron->log('ERROR: ' . $e->getMessage());
} finally {
    $cron->releaseLock();
}
