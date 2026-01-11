#!/usr/bin/php8.2
<?php

declare(strict_types=1);

use Mata\Services\Foundation\Dto\EventContext;
use Mata\Services\Foundation\Dto\OpaAlertDecisionConfig;
use Mata\Services\Foundation\OpaAlertDecisionService;

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
    $opaConfig = OpaAlertDecisionConfig::fromEnv();
    $alertDecisionService = new OpaAlertDecisionService($opaConfig);

    $supportedChannels = ['email'];
    $stats = [
        'considered' => 0,
        'enqueued' => 0,
        'skipped' => 0,
        'failed' => 0,
        'enrich_requested' => 0,
    ];

    // Fetch non-critical events (warning, info) - critical events handled by separate cronjob
    $events = $eventAggregator->getAlertDecisionCandidates(
        $alertDecisionService->getBatchLimit(),
        ['warning', 'info']
    );
    foreach ($events as $event) {
        ++$stats['considered'];

        // Evaluate threshold: check if event count meets alarm trigger criteria
        $shouldAlarm = $alertEvaluator->shouldTriggerAlarmForEvent(
            type: $event['type'],
            server: $event['server_id'],
            app: $event['application_id']
        );

        if (!$shouldAlarm) {
            $eventAggregator->markEventEvaluated((int) $event['id']);
            ++$stats['skipped'];
            $log->debug('alert_decision.threshold_not_met', [
                'event_id' => $event['id'] ?? null,
                'type' => $event['type'] ?? null,
            ]);

            continue;
        }

        try {
            $decision = $alertDecisionService->decide($event, $supportedChannels);
        } catch (Throwable $exception) {
            ++$stats['failed'];
            $log->error('alert_decision.opa_failed', [
                'event_id' => $event['id'] ?? null,
                'error' => $exception->getMessage(),
            ]);

            continue;
        }

        if (!$decision->allow) {
            $eventAggregator->markEventEvaluated((int) $event['id']);
            ++$stats['skipped'];

            if ($decision->reason !== null) {
                $log->info('alert_decision.skipped', [
                    'event_id' => $event['id'] ?? null,
                    'reason' => $decision->reason,
                ]);
            }

            continue;
        }

        if ($decision->enrich) {
            ++$stats['enrich_requested'];
            $log->info('alert_decision.enrichment_requested', [
                'event_id' => $event['id'] ?? null,
                'channels' => $decision->channels,
            ]);
        }

        // Filter channels based on alarm cooldowns
        $eventContext = EventContext::fromArray(json_decode($event['context'] ?? '{}', true) ?: []);
        $readyChannels = $alertEvaluator->filterReadyChannels(
            type: $event['type'],
            requestedChannels: $decision->channels,
            server: $event['server_id'],
            app: $event['application_id'],
            context: $eventContext
        );

        if (empty($readyChannels)) {
            $eventAggregator->markEventEvaluated((int) $event['id']);
            ++$stats['skipped'];
            $log->debug('alert_decision.all_channels_on_cooldown', [
                'event_id' => $event['id'] ?? null,
                'requested_channels' => $decision->channels,
            ]);

            continue;
        }

        try {
            $db->beginTransaction();

            // Enqueue alerts for ready channels only
            foreach ($readyChannels as $channel) {
                $alertQueueRepository->enqueue((int) $event['id'], $channel, $decision->priority);

                // Set alarm cooldown for this channel
                $alarmHash = $alertEvaluator->generateCooldownHash(
                    type: $event['type'],
                    server: $event['server_id'],
                    app: $event['application_id'],
                    context: $eventContext,
                    channel: $channel
                );
                $alarmCooldownMinutes = $alertEvaluator->getAlarmIntervalMinutes($event['type']);
                $eventRecorder->upsertAlarmCooldown($alarmHash, $alarmCooldownMinutes);

                ++$stats['enqueued'];
            }

            $eventAggregator->markEventEvaluated((int) $event['id']);
            $db->commit();
        } catch (Throwable $exception) {
            try {
                $db->rollback();
            } catch (Throwable) {
                // noop
            }
            ++$stats['failed'];
            $log->error('alert_decision.enqueue_failed', [
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
    $log->critical('evaluate_alert_decisions.unhandled_exception', [
        'error' => $exception->getMessage(),
    ]);
} finally {
    $cron->releaseLock();
}
