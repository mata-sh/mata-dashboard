<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use Mata\Services\Alarm\AlarmCreatorInterface;
use Mata\Services\Alarm\AlarmThresholdCooldownService;
use Mata\Services\Alarm\Decision\AlarmDecisionProcessingException;
use Mata\Services\Alarm\Decision\AlarmDecisionProcessor;
use Mata\Services\Alarm\Decision\AlarmDecisionServiceInterface;
use Mata\Services\Alarm\Dto\AlarmDecision;
use Mata\Services\Alarm\Dto\AlarmThresholdEvaluation;
use Mata\Services\Alarm\Dto\ResolvedNotificationRule;
use Mata\Services\Alarm\NotificationRuleResolver;
use Mata\Services\Event\EventAggregatorServiceInterface;
use Mata\Services\Event\EventRecorderService;
use Mata\Tests\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \Mata\Services\Alarm\Decision\AlarmDecisionProcessor
 *
 * @internal
 */
final class AlarmDecisionProcessorTest extends TestCase
{
    public function testThresholdSuppressionIsRecordedAndMarkedEvaluated(): void
    {
        $evaluator = $this->createMock(AlarmThresholdCooldownService::class);
        $evaluator->expects($this->once())
            ->method('evaluateAlarmForEvent')
            ->willReturn(new AlarmThresholdEvaluation(false, ['strategy' => 'event_count_window']));
        $decisionService = $this->createMock(AlarmDecisionServiceInterface::class);
        $decisionService->expects($this->never())->method('decide');
        $creator = $this->createMock(AlarmCreatorInterface::class);
        $creator->expects($this->never())->method('create');
        $recorder = $this->createStub(EventRecorderService::class);
        $aggregator = $this->expectRecordedOutcome('suppressed');

        $result = $this->processor($evaluator, $decisionService, $creator, $recorder, $aggregator)
            ->process($this->event());

        $this->assertTrue($result->skipped);
        $this->assertSame(0, $result->enqueuedCount);
        $this->assertSame('fail', $result->record->toArray()['threshold_gate']);
        $this->assertSame(['strategy' => 'event_count_window'], $result->record->toArray()['trigger_evidence']);
    }

    public function testMissingChannelsAreRecordedWithoutPolicyEvaluation(): void
    {
        $evaluator = $this->readyEvaluator([]);
        $decisionService = $this->createMock(AlarmDecisionServiceInterface::class);
        $decisionService->expects($this->never())->method('decide');
        $creator = $this->createMock(AlarmCreatorInterface::class);
        $creator->expects($this->never())->method('create');

        $result = $this->processor(
            $evaluator,
            $decisionService,
            $creator,
            $this->createStub(EventRecorderService::class),
            $this->expectRecordedOutcome('suppressed'),
        )->process($this->event());

        $this->assertTrue($result->skipped);
        $this->assertSame('deny', $result->record->toArray()['policy_gate']);
        $this->assertSame('no_channels_supplied', $result->record->toArray()['policy_reason']);
    }

    public function testPolicyDenialIsRecordedAndMarkedEvaluated(): void
    {
        $evaluator = $this->readyEvaluator([]);
        $decisionService = $this->createMock(AlarmDecisionServiceInterface::class);
        $decisionService->expects($this->once())
            ->method('decide')
            ->willReturn(new AlarmDecision(false, [], reason: 'maintenance_window'));
        $creator = $this->createMock(AlarmCreatorInterface::class);
        $creator->expects($this->never())->method('create');
        $recorder = $this->createStub(EventRecorderService::class);
        $aggregator = $this->expectRecordedOutcome('suppressed');

        $result = $this->processor($evaluator, $decisionService, $creator, $recorder, $aggregator)
            ->process($this->event(), ['email']);

        $this->assertTrue($result->skipped);
        $this->assertSame('deny', $result->record->toArray()['policy_gate']);
        $this->assertSame('maintenance_window', $result->record->toArray()['policy_reason']);
    }

    public function testDisabledNotificationRuleCreatesDashboardOnlyAlarm(): void
    {
        $evaluator = $this->readyEvaluator([]);
        $decisionService = $this->createMock(AlarmDecisionServiceInterface::class);
        $decisionService->expects($this->once())
            ->method('decide')
            ->with(
                $this->event(),
                [],
                $this->callback(static fn (ResolvedNotificationRule $rule): bool => $rule->toArray() === [
                    'enabled' => false,
                    'channels' => ['email'],
                    'reason' => 'event_type_disabled',
                ])
            )
            ->willReturn(new AlarmDecision(true, [], 50, reason: 'disk_alarm'));
        $creator = $this->createMock(AlarmCreatorInterface::class);
        $creator->expects($this->once())->method('create')->with(42, [], 50, [])->willReturn(99);

        $result = $this->processor(
            $evaluator,
            $decisionService,
            $creator,
            $this->createStub(EventRecorderService::class),
            $this->expectRecordedOutcome('undelivered'),
            ['event_types' => ['disk' => ['enabled' => false]]],
        )->process($this->event(), ['email']);

        $this->assertFalse($result->skipped);
        $this->assertSame(0, $result->enqueuedCount);
    }

    public function testEmptyConfiguredChannelsCreateDashboardOnlyAlarm(): void
    {
        $evaluator = $this->readyEvaluator([]);
        $decisionService = $this->decisionService(new AlarmDecision(true, [], 50, reason: 'disk_alarm'));
        $creator = $this->createMock(AlarmCreatorInterface::class);
        $creator->expects($this->once())->method('create')->with(42, [], 50, [])->willReturn(99);

        $result = $this->processor(
            $evaluator,
            $decisionService,
            $creator,
            $this->createStub(EventRecorderService::class),
            $this->expectRecordedOutcome('undelivered'),
            ['event_types' => ['disk' => ['channels' => []]]],
        )->process($this->event(), ['email']);

        $this->assertFalse($result->skipped);
    }

    public function testDecisionServiceReceivesConstrainedChannelsAndResolvedRule(): void
    {
        $evaluator = $this->readyEvaluator([]);
        $decisionService = $this->createMock(AlarmDecisionServiceInterface::class);
        $decisionService->expects($this->once())
            ->method('decide')
            ->with(
                $this->event(),
                ['email'],
                $this->callback(static fn (ResolvedNotificationRule $rule): bool => $rule->toArray() === [
                    'enabled' => true,
                    'channels' => ['email'],
                    'reason' => null,
                ])
            )
            ->willReturn(new AlarmDecision(false, [], reason: 'maintenance_window'));

        $this->processor(
            $evaluator,
            $decisionService,
            $this->createStub(AlarmCreatorInterface::class),
            $this->createStub(EventRecorderService::class),
            $this->expectRecordedOutcome('suppressed'),
        )->process($this->event(), ['email', 'sms']);
    }

    public function testNoReadyChannelsCreatesAnUndeliveredAlarm(): void
    {
        $evaluator = $this->readyEvaluator([]);
        $decisionService = $this->decisionService(new AlarmDecision(true, ['email'], 50));
        $creator = $this->createMock(AlarmCreatorInterface::class);
        $creator->expects($this->once())
            ->method('create')
            ->with(42, [], 50, [])
            ->willReturn(99);
        $recorder = $this->createMock(EventRecorderService::class);
        $recorder->expects($this->never())->method('upsertAlarmCooldown');
        $aggregator = $this->expectRecordedOutcome('undelivered');

        $result = $this->processor($evaluator, $decisionService, $creator, $recorder, $aggregator)
            ->process($this->event(), ['email']);

        $this->assertFalse($result->skipped);
        $this->assertSame(0, $result->enqueuedCount);
        $this->assertSame(['email'], $result->record->toArray()['unavailable_channels']);
    }

    public function testReadyChannelsCreateDeliveriesAndCooldowns(): void
    {
        $evidence = ['strategy' => 'immediate', 'observed_count' => 1];
        $evaluator = $this->createMock(AlarmThresholdCooldownService::class);
        $evaluator->expects($this->once())
            ->method('evaluateAlarmForEvent')
            ->willReturn(new AlarmThresholdEvaluation(true, $evidence));
        $evaluator->expects($this->once())
            ->method('filterReadyChannels')
            ->willReturn(['email']);
        $evaluator->expects($this->once())
            ->method('generateCooldownHash')
            ->willReturn('alarm-hash');
        $evaluator->expects($this->once())
            ->method('getAlarmIntervalMinutes')
            ->with('disk')
            ->willReturn(30);
        $decisionService = $this->decisionService(new AlarmDecision(true, ['email'], 50, true));
        $creator = $this->createMock(AlarmCreatorInterface::class);
        $creator->expects($this->once())
            ->method('create')
            ->with(42, ['email'], 50, $evidence)
            ->willReturn(99);
        $recorder = $this->createMock(EventRecorderService::class);
        $recorder->expects($this->once())
            ->method('upsertAlarmCooldown')
            ->with('alarm-hash', 30);
        $aggregator = $this->expectRecordedOutcome('enqueued');

        $result = $this->processor($evaluator, $decisionService, $creator, $recorder, $aggregator)
            ->process($this->event(), ['email']);

        $this->assertSame(1, $result->enqueuedCount);
        $this->assertTrue($result->enrichmentRequested);
    }

    public function testPolicyFailureRemainsRetryable(): void
    {
        $evaluator = $this->readyEvaluator([]);
        $decisionService = $this->createStub(AlarmDecisionServiceInterface::class);
        $decisionService->method('decide')->willThrowException(new RuntimeException('OPA unavailable'));
        $creator = $this->createStub(AlarmCreatorInterface::class);
        $recorder = $this->createStub(EventRecorderService::class);
        $aggregator = $this->createMock(EventAggregatorServiceInterface::class);
        $aggregator->expects($this->never())->method('markEventEvaluated');

        try {
            $this->processor($evaluator, $decisionService, $creator, $recorder, $aggregator)
                ->process($this->event(), ['email']);
            $this->fail('Expected policy processing exception.');
        } catch (AlarmDecisionProcessingException $exception) {
            $this->assertSame(AlarmDecisionProcessingException::POLICY, $exception->getStage());
            $this->assertSame('OPA unavailable', $exception->getMessage());
        }
    }

    public function testDeliveryCreationFailureRemainsRetryable(): void
    {
        $evaluator = $this->readyEvaluator(['email']);
        $decisionService = $this->decisionService(new AlarmDecision(true, ['email'], enrich: true));
        $creator = $this->createStub(AlarmCreatorInterface::class);
        $creator->method('create')->willThrowException(new RuntimeException('database unavailable'));
        $recorder = $this->createStub(EventRecorderService::class);
        $aggregator = $this->createMock(EventAggregatorServiceInterface::class);
        $aggregator->expects($this->never())->method('markEventEvaluated');

        try {
            $this->processor($evaluator, $decisionService, $creator, $recorder, $aggregator)
                ->process($this->event(), ['email']);
            $this->fail('Expected delivery processing exception.');
        } catch (AlarmDecisionProcessingException $exception) {
            $this->assertSame(AlarmDecisionProcessingException::DELIVERY, $exception->getStage());
            $this->assertTrue($exception->isEnrichmentRequested());
            $this->assertSame('database unavailable', $exception->getMessage());
        }
    }

    public function testCompletionFailureIsClassifiedPerEvent(): void
    {
        $evaluator = $this->readyEvaluator([]);
        $decisionService = $this->decisionService(new AlarmDecision(false, [], reason: 'maintenance_window'));
        $creator = $this->createStub(AlarmCreatorInterface::class);
        $recorder = $this->createStub(EventRecorderService::class);
        $aggregator = $this->createMock(EventAggregatorServiceInterface::class);
        $aggregator->expects($this->once())
            ->method('markEventEvaluated')
            ->willThrowException(new RuntimeException('database unavailable'));

        try {
            $this->processor($evaluator, $decisionService, $creator, $recorder, $aggregator)
                ->process($this->event(), ['email']);
            $this->fail('Expected completion processing exception.');
        } catch (AlarmDecisionProcessingException $exception) {
            $this->assertSame(AlarmDecisionProcessingException::COMPLETION, $exception->getStage());
            $this->assertSame('database unavailable', $exception->getMessage());
        }
    }

    /** @param array<int,string> $readyChannels */
    private function readyEvaluator(array $readyChannels): AlarmThresholdCooldownService
    {
        $evaluator = $this->createStub(AlarmThresholdCooldownService::class);
        $evaluator->method('evaluateAlarmForEvent')->willReturn(new AlarmThresholdEvaluation(true, []));
        $evaluator->method('filterReadyChannels')->willReturn($readyChannels);

        return $evaluator;
    }

    private function decisionService(AlarmDecision $decision): AlarmDecisionServiceInterface
    {
        $service = $this->createStub(AlarmDecisionServiceInterface::class);
        $service->method('decide')->willReturn($decision);

        return $service;
    }

    private function expectRecordedOutcome(string $outcome): EventAggregatorServiceInterface
    {
        $aggregator = $this->createMock(EventAggregatorServiceInterface::class);
        $aggregator->expects($this->once())
            ->method('markEventEvaluated')
            ->with(42, $this->callback(
                static fn (array $record): bool => $record['outcome'] === $outcome
            ));

        return $aggregator;
    }

    /** @param array<string,mixed> $ruleOverrides */
    private function processor(
        AlarmThresholdCooldownService $evaluator,
        AlarmDecisionServiceInterface $decisionService,
        AlarmCreatorInterface $creator,
        EventRecorderService $recorder,
        EventAggregatorServiceInterface $aggregator,
        array $ruleOverrides = [],
        ?LoggerInterface $logger = null,
    ): AlarmDecisionProcessor {
        $resolver = new NotificationRuleResolver(array_replace([
            'defaults' => ['enabled' => true, 'channels' => ['email']],
            'event_types' => [],
            'application_overrides' => [],
        ], $ruleOverrides));

        return new AlarmDecisionProcessor(
            $evaluator,
            $resolver,
            $decisionService,
            $creator,
            $recorder,
            $aggregator,
            $logger,
        );
    }

    /** @return array<string,mixed> */
    private function event(): array
    {
        return [
            'id' => 42,
            'type' => 'disk',
            'level' => 'critical',
            'context' => '{}',
            'server_id' => 1,
            'application_id' => 2,
        ];
    }
}
