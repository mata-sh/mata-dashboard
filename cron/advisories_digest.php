#!/usr/bin/env php
<?php

declare(strict_types=1);

use Mata\Bootstrap\AdvisoryRuntime;
use Mata\Bootstrap\CronRuntime;
use Mata\Services\Advisory\Report\AdvisoryReportQuery;
use Mata\Utils\FileStateStore;

require_once __DIR__ . '/../vendor/autoload.php';

// Runs daily; mails the report when advisories.digest.every_days have passed since the last one.
$runtime = CronRuntime::boot(__FILE__);
$runtime->requireDatabase();

$log = $runtime->log;
$cron = $runtime->cron;

if (!$cron->acquireLock()) {
    exit("Already running.\n");
}

$failed = false;
$cron->startTimer();
$cron->log('Started');

try {
    $advisories = AdvisoryRuntime::build($runtime->paths, $runtime->appConfig, $runtime->db);
    $digest = $advisories->config->digest;
    if (!$advisories->config->configured || !$digest->enabled) {
        $cron->log('Advisory digest not enabled');
        $cron->touchHeartbeat();

        return;
    }

    $state = new FileStateStore($runtime->paths->tempPath, 'advisory-digest-state.json', $log);
    $lastSent = $state->load(static fn (): array => [])['last_sent_at'] ?? null;
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    // An hour of slack keeps a daily cron from drifting a day late.
    if (is_int($lastSent) && $lastSent > $now->getTimestamp() - $digest->everyDays * 86400 + 3600) {
        $cron->log('Advisory digest not due; last sent ' . gmdate('Y-m-d H:i', $lastSent) . ' UTC');
        $cron->touchHeartbeat();

        return;
    }

    $report = $advisories->reportBuilder()->build(AdvisoryReportQuery::lastDays($digest->everyDays, $now, $digest->relevantOnly), $now);
    $recipients = $advisories->digestMailer()->send($report);
    $state->save(['last_sent_at' => $now->getTimestamp()]);

    $cron->log(sprintf('Sent digest with %d advisories to %d recipients in %.3fs', $report->total, count($recipients), $cron->endTimer()));
    $cron->touchHeartbeat();
} catch (Throwable $exception) {
    $failed = true;
    $cron->log('ERROR: ' . $exception->getMessage());
    $log->error('advisories_digest.failed', ['exception' => $exception]);
} finally {
    $cron->releaseLock();
}

if ($failed) {
    exit(1);
}
