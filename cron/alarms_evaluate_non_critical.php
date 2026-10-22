#!/usr/bin/env php
<?php

declare(strict_types=1);

use Mata\Bootstrap\AlarmChannelRuntime;
use Mata\Bootstrap\CronRuntime;
use Mata\Bootstrap\MonitoringRuntime;
use Mata\Services\Alarm\Evaluation\AlarmDecisionServiceFactory;
use Mata\Services\Alarm\Evaluation\AlarmEvaluationException;
use Mata\Services\Alarm\Evaluation\AlarmEvaluationProcessor;
use Mata\Services\Alarm\Evaluation\NotificationRuleResolver;

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
$alarmCreator = $monitoringRuntime->alarmCreator;
$alarmEvaluator = $monitoringRuntime->alarmEvaluator;
$eventRecorder = $monitoringRuntime->eventRecorder;
$eventAggregator = $monitoringRuntime->eventAggregator;
$eventManager = $monitoringRuntime->eventManager;
$notificationRules = $monitoringRuntime->monitoringConfig->notificationRules;

if (!$cron->acquireLock()) {
    exit("Already running.\n");
}

$cron->startTimer();
$cron->log('Started');

$exitCode = 0;

try {
    $alarmDecisionService = AlarmDecisionServiceFactory::fromConfig($runtime->appConfig->alarmDecision);
    $notificationRuleResolver = new NotificationRuleResolver($notificationRules);
    $events = $eventAggregator->getAlarmDecisionCandidates(
        $alarmDecisionService->getBatchLimit(),
        ['warning', 'info']
    );
    $alarmEvaluationProcessor = new AlarmEvaluationProcessor(
        $alarmEvaluator,
        $notificationRuleResolver,
        $alarmDecisionService,
        $alarmCreator,
        $eventRecorder,
        $eventAggregator,
        $log,
    );

    $alarmChannels = AlarmChannelRuntime::build(
        paths: $runtime->paths,
        appConfig: $runtime->appConfig,
        log: $runtime->log,
        events: $eventManager,
    );
    $alarmChannels->reportUnknownRuleChannels($notificationRules, $eventManager);
    $stats = [
        'considered' => 0,
        'enqueued' => 0,
        'skipped' => 0,
        'failed' => 0,
        'enrich_requested' => 0,
    ];

    foreach ($events as $event) {
        ++$stats['considered'];

        try {
            $result = $alarmEvaluationProcessor->process($event, $alarmChannels->channels->names());
        } catch (AlarmEvaluationException $exception) {
            ++$stats['failed'];
            if ($exception->isEnrichmentRequested()) {
                ++$stats['enrich_requested'];
            }
            $eventId = $event['id'] ?? null;
            $error = $exception->getPrevious()?->getMessage() ?? $exception->getMessage();

            $logName = match ($exception->getStage()) {
                AlarmEvaluationException::POLICY => 'routine_alarm_decision.policy_failed',
                AlarmEvaluationException::DELIVERY => 'routine_alarm_decision.enqueue_failed',
                AlarmEvaluationException::COMPLETION => 'routine_alarm_decision.completion_failed',
            };
            $log->error($logName, [
                'event_id' => $eventId,
                'error' => $error,
            ]);

            continue;
        }

        if ($result->skipped) {
            ++$stats['skipped'];
        }
        $stats['enqueued'] += $result->enqueuedCount;
        if ($result->enrichmentRequested) {
            ++$stats['enrich_requested'];
        }
    }

    $cron->log(sprintf(
        'Finished in %.3fs (memory: %s) | considered=%d enqueued=%d skipped=%d failed=%d enrich=%d',
        $cron->endTimer(),
        $cron->memoryUsage(),
        $stats['considered'],
        $stats['enqueued'],
        $stats['skipped'],
        $stats['failed'],
        $stats['enrich_requested']
    ));
    $cron->touchHeartbeat();
} catch (Throwable $exception) {
    $exitCode = 1;
    $cron->log('ERROR: ' . $exception->getMessage());
    $log->critical('evaluate_routine_alarms.unhandled_exception', [
        'error' => $exception->getMessage(),
    ]);
} finally {
    $cron->releaseLock();
}

exit($exitCode);
