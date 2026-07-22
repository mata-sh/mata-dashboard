<?php

declare(strict_types=1);

namespace Mata\Services\Alarm\Decision;

use Mata\Services\Alarm\AlarmCreatorInterface;
use Mata\Services\Alarm\AlarmThresholdCooldownService;
use Mata\Services\Alarm\Dto\AlarmEvaluationRecord;
use Mata\Services\Alarm\Dto\AlarmProcessingResult;
use Mata\Services\Alarm\NotificationRuleResolver;
use Mata\Services\Event\Dto\EventContext;
use Mata\Services\Event\EventAggregatorServiceInterface;
use Mata\Services\Event\EventRecorderService;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

final class AlarmDecisionProcessor
{
    public function __construct(
        private AlarmThresholdCooldownService $alarmCooldownService,
        private NotificationRuleResolver $notificationRuleResolver,
        private AlarmDecisionServiceInterface $alarmDecisionService,
        private AlarmCreatorInterface $alarmCreator,
        private EventRecorderService $eventRecorder,
        private EventAggregatorServiceInterface $eventAggregator,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    private LoggerInterface $logger;

    /**
     * Evaluate, create, and record an alarm decision for one event.
     *
     * @param array<string,mixed>    $event
     * @param null|array<int,string> $availableChannels
     *
     * @throws AlarmDecisionProcessingException
     */
    public function process(array $event, ?array $availableChannels = null): AlarmProcessingResult
    {
        $record = new AlarmEvaluationRecord();
        $eventId = (int) $event['id'];

        $context = EventContext::fromArray(json_decode($event['context'] ?? '{}', true) ?: []);
        $evaluation = $this->alarmCooldownService->evaluateAlarmForEvent(
            type: (string) $event['type'],
            server: $event['server_id'] ?? null,
            app: $event['application_id'] ?? null,
            context: $context,
        );
        $record->setTriggerEvidence($evaluation->evidence);

        if (!$evaluation->passed) {
            $record
                ->setOutcome('suppressed')
                ->setThresholdGate('fail');

            return $this->complete($event, $record, 0, false, true);
        }

        $record->setThresholdGate('pass');

        if ($availableChannels === null) {
            $record
                ->setOutcome('suppressed')
                ->setPolicyGate('deny')
                ->setPolicyReason('no_channels_supplied')
                ->setRequestedChannels([]);

            return $this->complete($event, $record, 0, false, true);
        }

        $notificationRule = $this->notificationRuleResolver->resolve(
            (string) $event['type'],
            is_string($event['app_code'] ?? null) ? $event['app_code'] : null,
        );
        // Rules constrain deliveries; policy still decides logical alarm eligibility.
        $candidateChannels = $notificationRule->enabled
            ? array_values(array_intersect(
                $notificationRule->channels,
                $this->normalizeChannels($availableChannels),
            ))
            : [];

        try {
            $decision = $this->alarmDecisionService->decide($event, $candidateChannels, $notificationRule);
        } catch (Throwable $exception) {
            throw new AlarmDecisionProcessingException(
                AlarmDecisionProcessingException::POLICY,
                $exception
            );
        }

        if (!$decision->allow) {
            $record
                ->setOutcome('suppressed')
                ->setPolicyGate('deny')
                ->setPolicyReason($decision->reason)
                ->setRequestedChannels($decision->channels);

            return $this->complete($event, $record, 0, false, true);
        }

        $record
            ->setPolicyGate('allow')
            ->setPolicyReason($decision->reason)
            ->setPriority($decision->priority)
            ->setEnrich($decision->enrich)
            ->setRequestedChannels($decision->channels);

        $readyChannels = $this->alarmCooldownService->filterReadyChannels(
            type: (string) $event['type'],
            requestedChannels: $decision->channels,
            server: $event['server_id'] ?? null,
            app: $event['application_id'] ?? null,
            context: $context,
        );

        $record
            ->setReadyChannels($readyChannels)
            ->setOutcome($readyChannels === [] ? 'undelivered' : 'enqueued');

        try {
            $this->alarmCreator->create($eventId, $readyChannels, $decision->priority, $evaluation->evidence);

            foreach ($readyChannels as $channel) {
                $alarmHash = $this->alarmCooldownService->generateCooldownHash(
                    type: (string) $event['type'],
                    server: $event['server_id'] ?? null,
                    app: $event['application_id'] ?? null,
                    context: $context,
                    channel: $channel,
                );
                $this->eventRecorder->upsertAlarmCooldown(
                    $alarmHash,
                    $this->alarmCooldownService->getAlarmIntervalMinutes((string) $event['type'])
                );
            }
        } catch (Throwable $exception) {
            throw new AlarmDecisionProcessingException(
                AlarmDecisionProcessingException::DELIVERY,
                $exception,
                $decision->enrich,
            );
        }

        return $this->complete(
            $event,
            $record,
            count($readyChannels),
            $decision->enrich,
            false,
        );
    }

    /**
     * Persist and log one completed evaluation.
     *
     * @param array<string,mixed> $event
     */
    private function complete(
        array $event,
        AlarmEvaluationRecord $record,
        int $enqueuedCount,
        bool $enrichmentRequested,
        bool $skipped,
    ): AlarmProcessingResult {
        try {
            $this->logger->info('alarm_decision', array_merge([
                'event_id' => (int) $event['id'],
                'type' => $event['type'] ?? null,
                'level' => $event['level'] ?? null,
                'server_id' => $event['server_id'] ?? null,
                'application_id' => $event['application_id'] ?? null,
                'application' => $event['app_code'] ?? null,
            ], $record->toArray()));
            $this->eventAggregator->markEventEvaluated((int) $event['id'], $record->toArray());
        } catch (Throwable $exception) {
            throw new AlarmDecisionProcessingException(
                AlarmDecisionProcessingException::COMPLETION,
                $exception,
                $enrichmentRequested,
            );
        }

        return new AlarmProcessingResult(
            $record,
            $enqueuedCount,
            $enrichmentRequested,
            $skipped,
        );
    }

    /** @param array<int,string> $channels @return list<string> */
    private function normalizeChannels(array $channels): array
    {
        $normalized = [];
        foreach ($channels as $channel) {
            if (!is_string($channel) || trim($channel) === '') {
                continue;
            }
            $normalized[] = strtolower(trim($channel));
        }

        return array_values(array_unique($normalized));
    }
}
