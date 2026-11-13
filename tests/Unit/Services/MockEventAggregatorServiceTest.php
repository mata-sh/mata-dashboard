<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use Mata\Services\Event\MockEventAggregatorService;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * @internal
 *
 * @coversNothing
 */
final class MockEventAggregatorServiceTest extends TestCase
{
    public function testNotificationShowcaseFillsMissingBellLevels(): void
    {
        $data = MockEventAggregatorService::withNotificationShowcase([
            'alarms' => [
                ['id' => 1, 'level' => 'critical'],
            ],
        ]);
        $service = new MockEventAggregatorService(demoData: $data);

        $this->assertSame([
            'critical' => 1,
            'warning' => 1,
            'error' => 1,
        ], $service->getUnacknowledgedAlarmCounts());
        $this->assertCount(3, $service->getLatestAlarms());
        $this->assertCount(2, $service->getLatestEvents());
    }

    public function testAlarmDecisionCandidatesDoNotContainMetrics(): void
    {
        $service = new MockEventAggregatorService(demoData: [
            'events' => [[
                'id' => 1,
                'datetime' => '2026-07-11T12:00:00Z',
                'type' => 'remaining_diskspace_too_low',
                'level' => 'critical',
                'metrics' => ['counts' => ['same_type_level_or_higher' => ['1h' => 1]]],
            ]],
        ]);

        $candidates = $service->getAlarmDecisionCandidates();

        $this->assertCount(1, $candidates);
        $this->assertArrayNotHasKey('metrics', $candidates[0]);
    }

    public function testNormalizesAlarmDeliveriesToTheReadModelContract(): void
    {
        $service = new MockEventAggregatorService(demoData: [
            'alarms' => [
                [
                    'id' => 1,
                    'created_at' => '2026-07-11 12:00:00',
                    'deliveries' => [
                        'Email' => [
                            'status' => 'retry',
                            'attempts' => '2',
                            'processed_at' => '2026-07-11 12:00:00',
                            'next_retry_at' => null,
                        ],
                    ],
                ],
                [
                    'id' => 2,
                    'created_at' => '2026-07-11 11:00:00',
                ],
            ],
        ]);

        $rows = $service->getLatestAlarms();

        $this->assertSame('retry', $rows[0]['deliveries']['email']['status']);
        $this->assertSame(2, $rows[0]['deliveries']['email']['attempts']);
        $this->assertSame('2026-07-11 12:00:00', $rows[0]['deliveries']['email']['processed_at']);
        $this->assertNull($rows[0]['deliveries']['email']['next_retry_at']);
        $this->assertInstanceOf(stdClass::class, $rows[1]['deliveries']);
        $this->assertArrayNotHasKey('channel', $rows[0]);
        $this->assertArrayNotHasKey('status', $rows[0]);
    }
}
