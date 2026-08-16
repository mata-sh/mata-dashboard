<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use Mata\Database\DatabaseInterface;
use Mata\Models\EventRepository;
use Mata\Tests\TestCase;

/**
 * @covers \Mata\Models\EventRepository
 *
 * @internal
 */
final class EventAggregatorAlarmDecisionTest extends TestCase
{
    public function testMarkEventEvaluatedClearsAlarmDecisionWhenNoneIsProvided(): void
    {
        $db = $this->createMock(DatabaseInterface::class);
        $db
            ->expects($this->once())
            ->method('runQuery')
            ->with(
                'UPDATE events SET evaluated_at = CURRENT_TIMESTAMP, alarm_decision = NULL WHERE id = ?',
                [42]
            )
            ->willReturn(1)
        ;

        (new EventRepository($db))->markEventEvaluated(42);
    }

    public function testMarkEventEvaluatedStoresEncodedAlarmDecision(): void
    {
        $decision = [
            'outcome' => 'alarmed',
            'requested_channels' => ['email'],
        ];
        $db = $this->createMock(DatabaseInterface::class);
        $db
            ->expects($this->once())
            ->method('runQuery')
            ->with(
                'UPDATE events SET evaluated_at = CURRENT_TIMESTAMP, alarm_decision = ? WHERE id = ?',
                ['{"outcome":"alarmed","requested_channels":["email"]}', 42]
            )
            ->willReturn(1)
        ;

        (new EventRepository($db))->markEventEvaluated(42, $decision);
    }

    public function testMarkEventEvaluatedClearsAlarmDecisionWhenEncodingFails(): void
    {
        $db = $this->createMock(DatabaseInterface::class);
        $db
            ->expects($this->once())
            ->method('runQuery')
            ->with(
                'UPDATE events SET evaluated_at = CURRENT_TIMESTAMP, alarm_decision = NULL WHERE id = ?',
                [42]
            )
            ->willReturn(1)
        ;

        (new EventRepository($db))->markEventEvaluated(42, ['value' => INF]);
    }
}
