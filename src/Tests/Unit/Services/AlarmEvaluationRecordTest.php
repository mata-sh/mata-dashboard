<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use Mata\Services\Alarm\Evaluation\Dto\AlarmEvaluationRecord;
use Mata\Tests\TestCase;

/**
 * @covers \Mata\Services\Alarm\Evaluation\Dto\AlarmEvaluationRecord
 *
 * @internal
 */
final class AlarmEvaluationRecordTest extends TestCase
{
    public function testToArrayHasExpectedDefaults(): void
    {
        $record = new AlarmEvaluationRecord();

        $this->assertSame([
            'outcome' => 'suppressed',
            'threshold_gate' => 'fail',
            'trigger_evidence' => null,
            'policy_gate' => null,
            'policy_reason' => null,
            'notification_rule_reason' => null,
            'requested_channels' => [],
            'ready_channels' => [],
            'unavailable_channels' => [],
            'priority' => null,
            'enrich' => false,
        ], $record->toArray());
    }

    public function testItDerivesUnavailableChannels(): void
    {
        $record = (new AlarmEvaluationRecord())
            ->setRequestedChannels(['email', 'slack'])
            ->setReadyChannels(['email']);

        $this->assertSame(['slack'], $record->toArray()['unavailable_channels']);
    }

    public function testItRecordsEnqueuedAndUndeliveredOutcomes(): void
    {
        $enqueued = (new AlarmEvaluationRecord())
            ->setOutcome('enqueued')
            ->setThresholdGate('pass')
            ->setPolicyGate('allow')
            ->setRequestedChannels(['email'])
            ->setReadyChannels(['email']);
        $undelivered = (new AlarmEvaluationRecord())
            ->setOutcome('undelivered')
            ->setThresholdGate('pass')
            ->setPolicyGate('allow')
            ->setRequestedChannels(['email'])
            ->setReadyChannels([]);

        $this->assertSame('enqueued', $enqueued->toArray()['outcome']);
        $this->assertSame('undelivered', $undelivered->toArray()['outcome']);
    }

    public function testThresholdFailureHasNoPolicyGate(): void
    {
        $record = new AlarmEvaluationRecord();

        $this->assertSame('suppressed', $record->toArray()['outcome']);
        $this->assertSame('fail', $record->toArray()['threshold_gate']);
        $this->assertNull($record->toArray()['policy_gate']);
    }
}
