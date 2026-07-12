#!/usr/bin/php8.2
<?php

declare(strict_types=1);

use Mata\Services\Retention\RetentionCleanupResult;
use Mata\Services\Retention\RetentionCleanupService;

$requiresDatabase = true;

extract(require_once __DIR__ . '/bootstrap.php');
extract(require_once __DIR__ . '/bootstrap_events.php');

if (!$cron->acquireLock()) {
    exit("Already running.\n");
}

$failed = false;
$result = null;
$cron->startTimer();
$cron->log('Started');

try {
    $retentionConfig = $monitoringSettings['data_retention'] ?? [];
    if (!is_array($retentionConfig)) {
        throw new RuntimeException('Invalid data retention configuration');
    }

    $service = new RetentionCleanupService($db, $log);
    $result = $service->cleanup(
        new DateTimeImmutable('now'),
        $retentionConfig,
        $cron->touchHeartbeat(...)
    );
    $duration = $cron->endTimer();
    $counts = $result->deletedCounts();
    $summary = sprintf(
        'Finished in %.3fs (memory: %s) | alarms=%d events=%d metrics=%d failures=%d',
        $duration,
        $cron->memoryUsage(),
        $result->alarmsDeleted,
        $result->eventsDeleted,
        array_sum($result->metricsDeleted),
        count($result->failures)
    );

    $cron->log($summary);
    $log->info('retention_cleanup.summary', [
        'cutoffs' => $result->cutoffs,
        'deleted' => $counts,
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
