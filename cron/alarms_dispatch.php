#!/usr/bin/php8.2
<?php

declare(strict_types=1);

use Mata\Config\AlarmLimitsLoader;
use Mata\Models\AlarmDeliveryRepository;
use Mata\Services\Alarm\AlarmDispatcher;
use Mata\Services\Alarm\AlarmEmailFactory;
use Mata\Services\Alarm\AlarmEmailSender;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

$requiresDatabase = true;

extract(require_once __DIR__ . '/bootstrap.php');

if (!$cron->acquireLock()) {
    exit("Already running.\n");
}

$cron->startTimer();
$cron->log('Started');

try {
    // --- Repositories ---
    $deliveryRepository = new AlarmDeliveryRepository($db);

    // --- Alarm Limits ---
    $limitsLoader = new AlarmLimitsLoader($log);
    $limitsConfig = $limitsLoader->load($paths->appPath . '/config/limits.json');

    // --- Senders & Dispatchers ---
    $twig = new Environment(
        new FilesystemLoader($paths->appPath . '/templates'),
        ['autoescape' => 'name'],
    );
    $emailFactory = new AlarmEmailFactory($twig, $appConfig->mail, $appConfig->general->appName);
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
