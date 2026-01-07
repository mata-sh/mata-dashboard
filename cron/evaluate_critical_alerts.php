#!/usr/bin/php8.2
<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use Watcher\Services\Foundation\Dto\EventContext;

extract(require_once __DIR__ . '/bootstrap.php');
extract(require_once __DIR__ . '/bootstrap_events.php');

if (!$cron->acquireLock()) {
    exit("Already running.\n");
}

$cron->startTimer();
$cron->touchHeartbeat();
$cron->log('Started');

try {
    // All event/alert infrastructure now loaded via bootstrap_events.php

    // --- OPA Client ---
    $opaConfig = resolveOpaConfig();
    $client = new Client([
        'base_uri' => $opaConfig['base_uri'],
        'timeout' => $opaConfig['timeout_seconds'],
        'headers' => [
            'Accept' => 'application/json',
        ],
    ]);

    $supportedChannels = ['email'];
    $stats = [
        'considered' => 0,
        'enqueued' => 0,
        'skipped' => 0,
        'failed' => 0,
        'enrich_requested' => 0,
    ];

    // Fetch CRITICAL events only
    $events = $eventAggregator->getAlertDecisionCandidates($opaConfig['batch_limit'], 'critical');
    foreach ($events as $event) {
        ++$stats['considered'];
        $cron->touchHeartbeat();

        // Evaluate threshold: check if event count meets alarm trigger criteria
        $shouldAlarm = $alertEvaluator->shouldTriggerAlarmForEvent(
            type: $event['type'],
            server: $event['server_id'],
            app: $event['application_id']
        );

        if (!$shouldAlarm) {
            $eventAggregator->markEventEvaluated((int) $event['id']);
            ++$stats['skipped'];
            $log->debug('critical_alert_decision.threshold_not_met', [
                'event_id' => $event['id'] ?? null,
                'type' => $event['type'] ?? null,
            ]);

            continue;
        }

        try {
            $decisionResponse = requestOpaDecision($client, $opaConfig['decision_path'], $event);
        } catch (Throwable $exception) {
            ++$stats['failed'];
            $log->error('critical_alert_decision.opa_failed', [
                'event_id' => $event['id'] ?? null,
                'error' => $exception->getMessage(),
            ]);

            continue;
        }

        $decision = normalizeOpaDecision($decisionResponse, $event, $supportedChannels);

        if (!$decision['allow']) {
            $eventAggregator->markEventEvaluated((int) $event['id']);
            ++$stats['skipped'];

            if ($decision['reason'] !== null) {
                $log->info('critical_alert_decision.skipped', [
                    'event_id' => $event['id'] ?? null,
                    'reason' => $decision['reason'],
                ]);
            }

            continue;
        }

        if ($decision['enrich']) {
            ++$stats['enrich_requested'];
            $log->info('critical_alert_decision.enrichment_requested', [
                'event_id' => $event['id'] ?? null,
                'channels' => $decision['channels'],
            ]);
        }

        // Filter channels based on alarm cooldowns
        $eventContext = EventContext::fromArray(json_decode($event['context'] ?? '{}', true) ?: []);
        $readyChannels = $alertEvaluator->filterReadyChannels(
            type: $event['type'],
            requestedChannels: $decision['channels'],
            server: $event['server_id'],
            app: $event['application_id'],
            context: $eventContext
        );

        if (empty($readyChannels)) {
            $eventAggregator->markEventEvaluated((int) $event['id']);
            ++$stats['skipped'];
            $log->debug('critical_alert_decision.all_channels_on_cooldown', [
                'event_id' => $event['id'] ?? null,
                'requested_channels' => $decision['channels'],
            ]);

            continue;
        }

        try {
            $db->beginTransaction();

            // Enqueue alerts for ready channels only
            foreach ($readyChannels as $channel) {
                $alertQueueRepository->enqueue((int) $event['id'], $channel, $decision['priority']);

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
            $log->error('critical_alert_decision.enqueue_failed', [
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

} catch (Throwable $exception) {
    $cron->log('ERROR: ' . $exception->getMessage());
    $log->critical('evaluate_critical_alerts.unhandled_exception', [
        'error' => $exception->getMessage(),
    ]);
} finally {
    $cron->releaseLock();
}

/**
 * @return array{base_uri:string,decision_path:string,timeout_seconds:float,batch_limit:int}
 */
function resolveOpaConfig(): array
{
    $baseUri = getenv('OPA_ALERT_BASE_URI') ?: 'http://127.0.0.1:1234';
    $decisionPath = getenv('OPA_ALERT_DECISION_PATH') ?: '/v1/data/alerts/decision';
    $timeout = (float) (getenv('OPA_ALERT_TIMEOUT_SECONDS') ?: 5);
    $batchLimit = (int) (getenv('OPA_ALERT_BATCH_LIMIT') ?: 100);

    return [
        'base_uri' => rtrim($baseUri, '/') . '/',
        'decision_path' => '/' . ltrim($decisionPath, '/'),
        'timeout_seconds' => max(1, $timeout),
        'batch_limit' => max(1, $batchLimit),
    ];
}

/**
 * @return array<string,mixed>
 *
 * @throws GuzzleException
 */
function requestOpaDecision(Client $client, string $path, array $event): array
{
    $payload = [
        'input' => buildOpaInput($event),
    ];

    $response = $client->post(ltrim($path, '/'), ['json' => $payload]);
    $body = (string) $response->getBody();
    $decoded = json_decode($body, true);

    if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException('OPA response is not valid JSON.');
    }

    if (!array_key_exists('result', $decoded)) {
        throw new RuntimeException('OPA response missing result payload.');
    }

    return $decoded;
}

/**
 * @param array<string,mixed> $event
 *
 * @return array<string,mixed>
 */
function buildOpaInput(array $event): array
{
    $context = null;
    if (is_string($event['context'] ?? null) && $event['context'] !== '') {
        $decoded = json_decode((string) $event['context'], true);
        $context = json_last_error() === JSON_ERROR_NONE ? $decoded : (string) $event['context'];
    } elseif ($event['context'] ?? null) {
        $context = $event['context'];
    }

    return [
        'event' => [
            'id' => (int) ($event['id'] ?? 0),
            'type' => (string) ($event['type'] ?? ''),
            'message' => (string) ($event['message'] ?? ''),
            'level' => (string) ($event['level'] ?? 'info'),
            'context' => $context,
            'datetime' => (string) ($event['datetime'] ?? ''),
            'alarm_channel' => $event['alarm_channel'] ?? null,
        ],
        'server' => [
            'id' => isset($event['server_id']) ? (int) $event['server_id'] : null,
            'name' => $event['server_name'] ?? null,
        ],
        'application' => [
            'id' => isset($event['application_id']) ? (int) $event['application_id'] : null,
            'code' => $event['app_code'] ?? null,
            'name' => $event['app_name'] ?? null,
        ],
    ];
}

/**
 * @param array<string,mixed> $response
 * @param array<string,mixed> $event
 * @param array<int,string>   $supportedChannels
 *
 * @return array{allow:bool,channels:array<int,string>,priority:int,enrich:bool,reason:?string}
 */
function normalizeOpaDecision(array $response, array $event, array $supportedChannels): array
{
    $result = $response['result'] ?? null;
    $data = is_array($result) ? $result : [];
    $reason = null;

    $allow = match (true) {
        is_bool($result) => $result,
        array_key_exists('allow', $data) => (bool) $data['allow'],
        array_key_exists('enqueue', $data) => (bool) $data['enqueue'],
        array_key_exists('alert', $data) => (bool) $data['alert'],
        array_key_exists('should_alert', $data) => (bool) $data['should_alert'],
        default => false,
    };

    $channels = [];
    if (is_array($data['channels'] ?? null)) {
        foreach ($data['channels'] as $channel) {
            if (is_string($channel) && $channel !== '') {
                $channels[] = strtolower(trim($channel));
            }
        }
    } elseif (is_string($data['channel'] ?? null) && $data['channel'] !== '') {
        $channels[] = strtolower(trim((string) $data['channel']));
    }

    if ($channels === [] && $allow) {
        $fallback = is_string($event['alarm_channel'] ?? null) ? (string) $event['alarm_channel'] : 'email';
        $channels = [strtolower(trim($fallback))];
    }

    $channels = array_values(array_unique(array_intersect($channels, $supportedChannels)));

    $priority = isset($data['priority']) && is_numeric($data['priority'])
        ? (int) $data['priority']
        : 100;

    $enrich = false;
    if (array_key_exists('enrich', $data)) {
        $enrich = (bool) $data['enrich'];
    } elseif (array_key_exists('llm_enrich', $data)) {
        $enrich = (bool) $data['llm_enrich'];
    }

    if ($allow && $channels === []) {
        $allow = false;
        $reason = 'no_supported_channels';
    }

    return [
        'allow' => $allow,
        'channels' => $channels,
        'priority' => $priority,
        'enrich' => $enrich,
        'reason' => $reason,
    ];
}
