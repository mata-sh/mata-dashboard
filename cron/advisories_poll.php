#!/usr/bin/env php
<?php

declare(strict_types=1);

use Mata\Bootstrap\AdvisoryRuntime;
use Mata\Bootstrap\CronRuntime;
use Mata\Bootstrap\MonitoringRuntime;
use Mata\Services\Advisory\Dto\AdvisoryPollStatus;

require_once __DIR__ . '/../vendor/autoload.php';

$runtime = CronRuntime::boot(__FILE__);
$runtime->requireDatabase();
$monitoringRuntime = MonitoringRuntime::build(
    paths: $runtime->paths,
    appConfig: $runtime->appConfig,
    log: $runtime->log,
    db: $runtime->db,
);

$log = $runtime->log;
$cron = $runtime->cron;
$eventManager = $monitoringRuntime->eventManager;

if (!$cron->acquireLock()) {
    exit("Already running.\n");
}

$failed = false;
$cron->startTimer();
$cron->log('Started');

try {
    $cron->touchHeartbeat();
    if ($runtime->appConfig->environment->env === 'demo') {
        $cron->log('Demo environment: advisory polling skipped');

        return;
    }

    $advisories = AdvisoryRuntime::build($runtime->paths, $runtime->appConfig, $runtime->db, $eventManager);
    $sources = $advisories->config->enabledSources();
    if ($sources === []) {
        $cron->log($advisories->config->configured ? 'No enabled advisory sources' : 'advisories.json not configured');

        return;
    }

    // Every source is polled when due; the poller tracks each source's interval and failures.
    $poller = $advisories->poller($eventManager, $log);
    $totals = ['polled' => 0, 'failed' => 0, 'new' => 0, 'notified' => 0];
    foreach ($sources as $source) {
        try {
            $result = $poller->poll($source, new DateTimeImmutable());
        } catch (Throwable $exception) {
            $log->error('advisories_poll.source_failed', ['source' => $source->key, 'exception' => $exception]);
            ++$totals['failed'];

            continue;
        }
        $cron->log("{$source->key}: " . $result->summaryLine());
        $cron->touchHeartbeat();
        $totals['polled'] += $result->status === AdvisoryPollStatus::Polled ? 1 : 0;
        $totals['failed'] += $result->status === AdvisoryPollStatus::Failed ? 1 : 0;
        $totals['new'] += $result->stored;
        $totals['notified'] += $result->notified;
    }

    $duration = $cron->endTimer();
    $cron->log(sprintf(
        'Finished in %.3fs (memory: %s) | polled=%d failed=%d new=%d notified=%d',
        $duration,
        $cron->memoryUsage(),
        $totals['polled'],
        $totals['failed'],
        $totals['new'],
        $totals['notified'],
    ));
    $cron->touchHeartbeat();
} catch (Throwable $exception) {
    $failed = true;
    $cron->log('ERROR: ' . $exception->getMessage());
    $log->critical('advisories_poll.unhandled_exception', ['exception' => $exception]);
} finally {
    $cron->releaseLock();
}

if ($failed) {
    exit(1);
}
