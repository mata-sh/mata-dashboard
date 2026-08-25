#!/usr/bin/php8.2
<?php

declare(strict_types=1);

use Mata\Bootstrap\CronRuntime;
use Mata\Config\Monitoring\Loader\AlarmRateLimitsConfigLoader;
use Mata\Models\AlarmDeliveryRepository;
use Mata\Services\Alarm\AlarmDispatcher;
use Mata\Services\Alarm\AlarmEmailFactory;
use Mata\Services\Alarm\AlarmEmailSender;
use Mata\Services\Alarm\AlarmPresenter;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

require_once __DIR__ . '/../vendor/autoload.php';

$runtime = CronRuntime::boot(__FILE__);
$runtime->requireDatabase();

$paths = $runtime->paths;
$log = $runtime->log;
$cron = $runtime->cron;
$appConfig = $runtime->appConfig;
$db = $runtime->db;
$mailer = $runtime->mailer;

if (!$cron->acquireLock()) {
    exit("Already running.\n");
}

$cron->startTimer();
$cron->log('Started');

try {
    // --- Repositories ---
    $deliveryRepository = new AlarmDeliveryRepository($db);

    // --- Alarm Limits ---
    $limitsLoader = new AlarmRateLimitsConfigLoader($log);
    $limitsConfig = $limitsLoader->load($paths->appPath . '/config');

    // --- Senders & Dispatchers ---
    $twig = new Environment(
        new FilesystemLoader($paths->appPath . '/templates'),
        ['autoescape' => 'name'],
    );
    $servers = [];

    try {
        $contents = file_get_contents($paths->appPath . '/config/servers.json');
        $servers = is_string($contents) ? json_decode($contents, true, flags: JSON_THROW_ON_ERROR) : [];
        if (!is_array($servers)) {
            throw new RuntimeException('Server configuration must contain a JSON object.');
        }
    } catch (Throwable $exception) {
        $log->warning('Alarm email server presentation unavailable; rendering without server color or environment fallback.', [
            'exception' => $exception->getMessage(),
        ]);
        $servers = [];
    }
    $emailFactory = new AlarmEmailFactory(
        $twig,
        $appConfig->mail,
        $appConfig->general->appName,
        new AlarmPresenter($servers),
    );
    $emailSender = new AlarmEmailSender($mailer, $emailFactory, $log);
    $dispatcher = new AlarmDispatcher($deliveryRepository, $limitsConfig, $emailSender, $log);

    // --- Dispatch Alarm Deliveries ---
    $dispatcher->dispatch(new DateTimeImmutable('now'), 100);

    $cron->log("Finished in {$cron->endTimer()}s, memory: {$cron->memoryUsage()}");
    $cron->touchHeartbeat();

} catch (Throwable $e) {
    $cron->log('ERROR: ' . $e->getMessage());
} finally {
    $cron->releaseLock();
}
