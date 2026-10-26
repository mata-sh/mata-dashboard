#!/usr/bin/env php
<?php

declare(strict_types=1);

use Mata\Bootstrap\CronRuntime;
use Mata\Services\Cron\CronHeartbeatPruner;

require_once __DIR__ . '/../vendor/autoload.php';

$runtime = CronRuntime::boot(__FILE__);
$log = $runtime->log;
$cron = $runtime->cron;

if (!$cron->acquireLock()) {
    exit("Already running.\n");
}

$failed = false;
$cron->startTimer();
$cron->log('Started');

try {
    $removed = CronHeartbeatPruner::fromPaths($runtime->paths)->prune();
    $duration = $cron->endTimer();
    $cron->log(sprintf('Finished in %.3fs | removed=%d', $duration, $removed));
    $log->info('cron_history_cleanup.summary', [
        'removed' => $removed,
        'duration_seconds' => $duration,
    ]);
    $cron->touchHeartbeat();
} catch (Throwable $exception) {
    $failed = true;
    $cron->log('ERROR: ' . $exception->getMessage());
    $log->critical('cron_history_cleanup.unhandled_exception', [
        'exception' => $exception,
    ]);
} finally {
    $cron->releaseLock();
}

if ($failed) {
    exit(1);
}
