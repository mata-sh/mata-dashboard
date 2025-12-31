#!/usr/bin/php8.2
<?php

declare(strict_types=1);

use DateTimeImmutable;
use Watcher\Config\AlertLimitsLoader;
use Watcher\Models\AlertQueueRepository;
use Watcher\Services\Foundation\AlertDispatcher;
use Watcher\Services\Foundation\AlertEmailSender;

extract(require_once __DIR__ . '/bootstrap.php');

if (!$cron->acquireLock()) {
    exit("Already running.\n");
}

$cron->startTimer();
$cron->touchHeartbeat();
$cron->log('Started');

try {
    // --- Repositories ---
    $queueRepository = new AlertQueueRepository($db);

    // --- Alert Limits ---
    $limitsLoader = new AlertLimitsLoader($log);
    $limitsConfig = $limitsLoader->load($paths->appPath . '/config/limits.json');

    // --- Mail Configuration ---
    $mailConfig = [
        'from_email' => $appConfig->mail->fromEmail,
        'from_name' => $appConfig->mail->fromName,
        'recipients' => $appConfig->mail->alertRecipients,
    ];

    // --- Senders & Dispatchers ---
    $emailSender = new AlertEmailSender($mailer, $mailConfig);
    $dispatcher = new AlertDispatcher($queueRepository, $limitsConfig, $emailSender);

    // --- Dispatch Alerts ---
    $dispatcher->dispatch(new DateTimeImmutable('now'), 100);

    $cron->log("Finished in {$cron->endTimer()}s, memory: {$cron->memoryUsage()}");
} catch (Throwable $e) {
    $cron->log('ERROR: ' . $e->getMessage());
} finally {
    $cron->releaseLock();
}
