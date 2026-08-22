#!/usr/bin/env php
<?php

declare(strict_types=1);

use Mata\Bootstrap\CronRuntime;
use MataSh\RateLimiter\RateLimiter;
use MataSh\RateLimiter\Storage\FileStorage;

require_once __DIR__ . '/../vendor/autoload.php';

$runtime = CronRuntime::boot(__FILE__);
$log = $runtime->log;
$cron = $runtime->cron;
$appConfig = $runtime->appConfig;

if (!$cron->acquireLock()) {
    exit("Already running.\n");
}

$failed = false;
$cron->startTimer();
$cron->log('Started');

try {
    $config = $appConfig->rateLimiting;
    if (!$config->enabled || $config->storageType !== 'file') {
        $cron->log('No filesystem rate-limit cleanup required');
        $cron->touchHeartbeat();

        return;
    }

    $directory = $config->filePath ?: sys_get_temp_dir() . '/cache/rate_limit';
    $removed = (new RateLimiter(new FileStorage($directory), $log))->cleanup(1000);
    $duration = $cron->endTimer();
    $cron->log(sprintf('Finished in %.3fs | expired=%d', $duration, $removed));
    $log->info('rate_limit_cleanup.summary', [
        'expired_removed' => $removed,
        'duration_seconds' => $duration,
    ]);
    $cron->touchHeartbeat();
} catch (Throwable $exception) {
    $failed = true;
    $cron->log('ERROR: ' . $exception->getMessage());
    $log->critical('rate_limit_cleanup.unhandled_exception', [
        'exception' => $exception,
    ]);
} finally {
    $cron->releaseLock();
}

if ($failed) {
    exit(1);
}
