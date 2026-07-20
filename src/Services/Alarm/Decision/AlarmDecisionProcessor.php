<?php

declare(strict_types=1);

namespace Mata\Services\Alarm\Decision;

use Mata\Services\Alarm\AlarmCreatorInterface;
use Mata\Services\Alarm\AlarmThresholdCooldownService;
use Mata\Services\Alarm\Dto\AlarmEvaluationRecord;
use Mata\Services\Alarm\Dto\AlarmProcessingResult;
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
     * @param array<string,mixed> $event
     * @param array<int,string>   $supportedChannels
     *
     * @throws AlarmDecisionProcessingException
     */
    public function process(array $event, array $supportedChannels = ['email']): AlarmProcessingResult
    {
        $record = new AlarmEvaluationRecord();
        $eventId = (int) $event['id'];

        if (!$this->alarmCooldownService->shouldTriggerAlarmForEvent(
            type: (string) $event['type'],
            server: $event['server_id'] ?? null,
            app: $event['application_id'] ?? null,
        )) {
            $record
                ->setOutcome('suppressed')
                ->setThresholdGate('fail');

            return $this->complete($event, $record, 0, false, true);
        }

        $record->setThresholdGate('pass');

        try {
            $decision = $this->alarmDecisionService->decide($event, $supportedChannels);
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
                ->setPolicyReason($decision->reason);

            return $this->complete($event, $record, 0, false, true);
        }

        $record
            ->setPolicyGate('allow')
            ->setPolicyReason($decision->reason)
            ->setPriority($decision->priority)
            ->setEnrich($decision->enrich)
            ->setRequestedChannels($decision->channels);

        $context = EventContext::fromArray(json_decode($event['context'] ?? '{}', true) ?: []);
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
            $this->alarmCreator->create($eventId, $readyChannels, $decision->priority);

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
        $this->logger->info('alarm_decision', array_merge([
            'event_id' => (int) $event['id'],
            'type' => $event['type'] ?? null,
            'level' => $event['level'] ?? null,
            'server_id' => $event['server_id'] ?? null,
            'application_id' => $event['application_id'] ?? null,
        ], $record->toArray()));
        $this->eventAggregator->markEventEvaluated((int) $event['id'], $record->toArray());

        return new AlarmProcessingResult(
            $record,
            $enqueuedCount,
            $enrichmentRequested,
            $skipped,
        );
    }
}
