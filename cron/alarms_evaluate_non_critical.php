#!/usr/bin/php8.2
<?php

declare(strict_types=1);

use Mata\Services\Event\Dto\EventContext;
use Mata\Services\Alarm\Dto\OpaAlarmDecisionConfig;
use Mata\Services\Alarm\OpaAlarmDecisionService;

$requiresDatabase = true;

extract(require_once __DIR__ . '/bootstrap.php');
extract(require_once __DIR__ . '/bootstrap_events.php');

if (!$cron->acquireLock()) {
    exit("Already running.\n");
}

$cron->startTimer();
$cron->log('Started');

try {
    // --- OPA Decision Service ---
    $opaConfig = OpaAlarmDecisionConfig::fromEnv();
    $alarmDecisionService = new OpaAlarmDecisionService($opaConfig);

    $supportedChannels = ['email'];
    $stats = [
        'considered' => 0,
        'enqueued' => 0,
        'skipped' => 0,
        'failed' => 0,
        'enrich_requested' => 0,
    ];

    // Fetch non-critical events (warning, info) - critical events handled by separate cronjob
    $events = $eventAggregator->getAlarmDecisionCandidates(
        $alarmDecisionService->getBatchLimit(),
        ['warning', 'info']
    );
    foreach ($events as $event) {
        ++$stats['considered'];

        // Evaluate threshold: check if event count meets alarm trigger criteria
        $shouldAlarm = $alarmEvaluator->shouldTriggerAlarmForEvent(
            type: $event['type'],
            server: $event['server_id'],
            app: $event['application_id']
        );

        if (!$shouldAlarm) {
            $eventAggregator->markEventEvaluated((int) $event['id']);
            ++$stats['skipped'];
            $log->debug('alarm_decision.threshold_not_met', [
                'event_id' => $event['id'] ?? null,
                'type' => $event['type'] ?? null,
            ]);

            continue;
        }

        try {
            $decision = $alarmDecisionService->decide($event, $supportedChannels);
        } catch (Throwable $exception) {
            ++$stats['failed'];
            $log->error('alarm_decision.opa_failed', [
                'event_id' => $event['id'] ?? null,
                'error' => $exception->getMessage(),
            ]);

            continue;
        }

        if (!$decision->allow) {
            $eventAggregator->markEventEvaluated((int) $event['id']);
            ++$stats['skipped'];

            if ($decision->reason !== null) {
                $log->info('alarm_decision.skipped', [
                    'event_id' => $event['id'] ?? null,
                    'reason' => $decision->reason,
                ]);
            }

            continue;
        }

        if ($decision->enrich) {
            ++$stats['enrich_requested'];
            $log->info('alarm_decision.enrichment_requested', [
                'event_id' => $event['id'] ?? null,
                'channels' => $decision->channels,
            ]);
        }

        // Filter channels based on alarm cooldowns
        $eventContext = EventContext::fromArray(json_decode($event['context'] ?? '{}', true) ?: []);
        $readyChannels = $alarmEvaluator->filterReadyChannels(
            type: $event['type'],
            requestedChannels: $decision->channels,
            server: $event['server_id'],
            app: $event['application_id'],
            context: $eventContext
        );

        try {
            $alarmCreator->create((int) $event['id'], $readyChannels, $decision->priority);

            foreach ($readyChannels as $channel) {
                $alarmHash = $alarmEvaluator->generateCooldownHash(
                    type: $event['type'],
                    server: $event['server_id'],
                    app: $event['application_id'],
                    context: $eventContext,
                    channel: $channel
                );
                $eventRecorder->upsertAlarmCooldown(
                    $alarmHash,
                    $alarmEvaluator->getAlarmIntervalMinutes($event['type'])
                );
            }

            $eventAggregator->markEventEvaluated((int) $event['id']);
            $stats['enqueued'] += count($readyChannels);
            if ($readyChannels === []) {
                $log->debug('alarm_decision.no_ready_channels', ['event_id' => $event['id'] ?? null]);
            }
        } catch (Throwable $exception) {
            ++$stats['failed'];
            $log->error('alarm_decision.enqueue_failed', [
                'event_id' => $event['id'] ?? null,
                'error' => $exception->getMessage(),
            ]);
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
    $cron->log('ERROR: ' . $exception->getMessage());
    $log->critical('evaluate_alarm_decisions.unhandled_exception', [
        'error' => $exception->getMessage(),
    ]);
} finally {
    $cron->releaseLock();
}
