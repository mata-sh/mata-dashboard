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
