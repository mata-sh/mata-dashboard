<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use Mata\Core\DatabaseInterface;
use Mata\Models\AlarmRepository;
use Mata\Models\EventRepository;
use Mata\Services\Event\EventAggregatorService;
use Mata\Services\Foundation\IdResolver;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 *
 * @covers \Mata\Services\Event\EventAggregatorService
 */
final class EventAggregatorServiceTest extends TestCase
{
    public function testAlarmDecisionCandidatesAreReturnedWithoutDecisionMetrics(): void
    {
        $events = [[
            'id' => 42,
            'type' => 'disk_usage',
            'level' => 'critical',
            'datetime' => '2026-07-11T12:00:00Z',
        ]];
        $eventRepository = $this->createMock(EventRepository::class);
        $eventRepository
            ->expects($this->once())
            ->method('getAlarmDecisionCandidates')
            ->with(100, null)
            ->willReturn($events)
        ;
        $database = $this->createMock(DatabaseInterface::class);
        $database->expects($this->never())->method('runQuery');
        $alarmRepository = new AlarmRepository($database);
        $idResolver = $this->createStub(IdResolver::class);

        $service = new EventAggregatorService($eventRepository, $alarmRepository, $idResolver);

        $this->assertSame($events, $service->getAlarmDecisionCandidates());
    }
}
