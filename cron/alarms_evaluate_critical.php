#!/usr/bin/php8.2
<?php

declare(strict_types=1);

use Mata\Services\Alarm\Decision\AlarmDecisionProcessingException;
use Mata\Services\Alarm\Decision\AlarmDecisionProcessor;
use Mata\Services\Alarm\Decision\AlarmDecisionServiceFactory;

$requiresDatabase = true;

extract(require_once __DIR__ . '/bootstrap.php');
extract(require_once __DIR__ . '/bootstrap_events.php');

if (!$cron->acquireLock()) {
    exit("Already running.\n");
}

$cron->startTimer();
$cron->log('Started');

$exitCode = 0;

try {
    $alarmDecisionService = AlarmDecisionServiceFactory::fromEnv();
    $alarmDecisionProcessor = new AlarmDecisionProcessor(
        $alarmEvaluator,
        $alarmDecisionService,
        $alarmCreator,
        $eventRecorder,
        $eventAggregator,
        $log,
    );

    $supportedChannels = ['email'];
    $stats = [
        'considered' => 0,
        'enqueued' => 0,
        'skipped' => 0,
        'failed' => 0,
        'enrich_requested' => 0,
    ];

    $events = $eventAggregator->getAlarmDecisionCandidates(
        $alarmDecisionService->getBatchLimit(),
        'critical'
    );
    foreach ($events as $event) {
        ++$stats['considered'];

        try {
            $result = $alarmDecisionProcessor->process($event, $supportedChannels);
        } catch (AlarmDecisionProcessingException $exception) {
            ++$stats['failed'];
            if ($exception->isEnrichmentRequested()) {
                ++$stats['enrich_requested'];
            }
            $eventId = $event['id'] ?? null;
            $error = $exception->getPrevious()?->getMessage() ?? $exception->getMessage();

            $logName = match ($exception->getStage()) {
                AlarmDecisionProcessingException::POLICY => 'critical_alarm_decision.policy_failed',
                AlarmDecisionProcessingException::DELIVERY => 'critical_alarm_decision.enqueue_failed',
                AlarmDecisionProcessingException::COMPLETION => 'critical_alarm_decision.completion_failed',
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
    $log->critical('evaluate_critical_alarms.unhandled_exception', [
        'error' => $exception->getMessage(),
    ]);
} finally {
    $cron->releaseLock();
}

exit($exitCode);
