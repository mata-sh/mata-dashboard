#!/usr/bin/env php
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
    $loadedLimits = (new AlarmRateLimitsConfigLoader())->load($paths->configPath);
    $eventManager->recordConfigProblems($loadedLimits->problems);
    $alarmRateLimits = $loadedLimits->section;

    // --- Senders & Dispatchers ---
    $alarmChannels = AlarmChannelRuntime::build(
        paths: $paths,
        appConfig: $appConfig,
        log: $log,
        events: $eventManager,
    );
    $alarmChannels->reportUnknownLimitChannels($alarmRateLimits, $eventManager);
    $senders = $alarmChannels->senders();
    $dispatcher = new AlarmDispatcher($deliveryRepository, $alarmRateLimits, $senders, $log);

    // --- Dispatch Alarm Deliveries ---
    $dispatcher->dispatch(new DateTimeImmutable('now'), 100);

    $cron->log("Finished in {$cron->endTimer()}s, memory: {$cron->memoryUsage()}");
    $cron->touchHeartbeat();

} catch (Throwable $e) {
    $cron->log('ERROR: ' . $e->getMessage());
} finally {
    $cron->releaseLock();
}
