<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use Mata\Database\DatabaseInterface;
use Mata\Models\AlarmRepository;
use Mata\Models\EventRepository;
use Mata\Services\Event\EventAggregatorService;
use Mata\Services\Identity\ServerApplicationIdResolver;
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
            'type' => 'remaining_diskspace_too_low',
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
        $database->expects($this->never())->method('runSelect');
        $database->expects($this->never())->method('runInsert');
        $database->expects($this->never())->method('runStatement');
        $alarmRepository = new AlarmRepository($database);
        $idResolver = $this->createStub(ServerApplicationIdResolver::class);

        $service = new EventAggregatorService($eventRepository, $alarmRepository, $idResolver);

        $this->assertSame($events, $service->getAlarmDecisionCandidates());
    }
}
