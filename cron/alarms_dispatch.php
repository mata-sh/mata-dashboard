#!/usr/bin/php8.2
<?php

declare(strict_types=1);

use Mata\Config\AlarmLimitsLoader;
use Mata\Models\AlarmDeliveryRepository;
use Mata\Services\Alarm\AlarmDispatcher;
use Mata\Services\Alarm\AlarmEmailFactory;
use Mata\Services\Alarm\AlarmPresenter;
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
