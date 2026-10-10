#!/usr/bin/php8.2
<?php

declare(strict_types=1);

use Mata\Bootstrap\CronMonitoringRuntime;
use Mata\Bootstrap\CronRuntime;
use Mata\Services\Notification\SystemNotificationManager;
use Mata\Services\Retention\RetentionCleanupResult;
use Mata\Services\Retention\RetentionCleanupService;

require_once __DIR__ . '/../vendor/autoload.php';

$runtime = CronRuntime::boot(__FILE__);
$runtime->requireDatabase();
$monitoringRuntime = CronMonitoringRuntime::fromRuntime($runtime);

$log = $runtime->log;
$cron = $runtime->cron;
$db = $runtime->db;
$monitoringConfig = $monitoringRuntime->monitoringConfig;

if (!$cron->acquireLock()) {
    exit("Already running.\n");
}

$failed = false;
$result = null;
$cron->startTimer();
$cron->log('Started');

try {
    $retentionConfig = $monitoringConfig->dataRetention;

    $service = new RetentionCleanupService($db, $log);
    $result = $service->cleanup(
        new DateTimeImmutable('now'),
        $retentionConfig,
        $cron->touchHeartbeat(...)
    );

    $notificationManager = new SystemNotificationManager($db, $log, $runtime->paths);
    $resolvedConfigWarningsCleanupSucceeded = $notificationManager->cleanupResolvedConfigWarnings(
        $runtime->validationWarnings
    );
    $cron->touchHeartbeat();
    $oldNotificationsCleanupSucceeded = $notificationManager->cleanupOldNotifications(30);

    $failed = !$resolvedConfigWarningsCleanupSucceeded || !$oldNotificationsCleanupSucceeded;
    $duration = $cron->endTimer();
    $counts = $result->deletedCounts();
    $summary = sprintf(
        'Finished in %.3fs (memory: %s) | alarms=%d events=%d metrics=%d notifications=%s config_warnings=%s failures=%d',
        $duration,
        $cron->memoryUsage(),
        $result->alarmsDeleted,
        $result->eventsDeleted,
        array_sum($result->metricsDeleted),
        $oldNotificationsCleanupSucceeded ? 'ok' : 'failed',
        $resolvedConfigWarningsCleanupSucceeded ? 'ok' : 'failed',
        count($result->failures)
    );

    $cron->log($summary);
    $log->info('retention_cleanup.summary', [
        'cutoffs' => $result->cutoffs,
        'deleted' => $counts,
        'notifications' => [
            'old_cleanup_succeeded' => $oldNotificationsCleanupSucceeded,
            'resolved_config_warnings_cleanup_succeeded' => $resolvedConfigWarningsCleanupSucceeded,
        ],
        'failures' => $result->failures,
        'duration_seconds' => $duration,
        'memory' => $cron->memoryUsage(),
    ]);
    $cron->touchHeartbeat();
} catch (Throwable $exception) {
    $failed = true;
    $cron->log('ERROR: ' . $exception->getMessage());
    $log->critical('retention_cleanup.unhandled_exception', [
        'error' => $exception->getMessage(),
    ]);
} finally {
    $cron->releaseLock();
}

if ($failed || ($result instanceof RetentionCleanupResult && $result->hasFailures())) {
    exit(1);
}
