<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use Exception;
use Mata\Services\Collect\ServerDataCollectorInterface;
use Mata\Services\Event\Dto\EventCommand;
use Mata\Services\Event\EventManager;
use Mata\Services\Monitor\ServerMonitor;
use Mata\Tests\TestCase;
use Monolog\Handler\NullHandler;
use Monolog\Logger;

/**
 * @covers \Mata\Services\Monitor\ServerMonitor
 *
 * @internal
 */
final class ServerMonitorTest extends TestCase
{
    private EventManager $eventManager;
    private ServerMonitor $monitor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->eventManager = $this->createStub(EventManager::class);
        $logger = new Logger('test');
        $logger->pushHandler(new NullHandler());
        $this->monitor = new ServerMonitor(
            $logger,
            $this->eventManager,
        );
    }

    private function useMockEventManager(): void
    {
        $this->eventManager = $this->createMock(EventManager::class);
        $logger = new Logger('test');
        $logger->pushHandler(new NullHandler());
        $this->monitor = new ServerMonitor($logger, $this->eventManager);
    }

    public function testCollectServerHealthDataReturnsStructuredPayload(): void
    {
        $collector = $this->createMock(ServerDataCollectorInterface::class);

        $collector->expects($this->once())
            ->method('getServerSystemData')
            ->with('serverData')
            ->willReturn([
                'result' => [
                    'disk_space_used' => 50,
                    'disk_space_capacity' => 100,
                    'total_system_filecount' => 1000,
                ],
                'cache_age' => 5,
                'cache_ttl' => 10,
                'from_cache' => true,
                'stale_cache' => false,
            ])
        ;

        $this->useMockEventManager();

        $this->eventManager
            ->expects($this->exactly(4))
            ->method('getServerDataThresholdLevel')
            ->willReturn('')
        ;

        $this->eventManager
            ->expects($this->never())
            ->method('handle')
        ;

        $payload = $this->monitor->collectServerHealthData($collector, 'srv-1', 'serverData');

        $this->assertArrayHasKey('data', $payload);
        $this->assertArrayHasKey('cache', $payload);
        $this->assertArrayHasKey('levels', $payload);
        $this->assertSame(5, $payload['cache']['age']);
        $this->assertSame(true, $payload['cache']['from_cache']);
    }

    public function testCollectServerHealthDataTriggersFilecountAlarm(): void
    {
        $collector = $this->createStub(ServerDataCollectorInterface::class);

        $collector->method('getServerSystemData')
            ->willReturn([
                'result' => [
                    'disk_space_used' => 10,
                    'disk_space_capacity' => 100,
                    'total_system_filecount' => 950000,
                ],
            ])
        ;

        $this->useMockEventManager();

        $this->eventManager
            ->expects($this->exactly(4))
            ->method('getServerDataThresholdLevel')
            ->willReturnOnConsecutiveCalls('', '', 'alarm', '')
        ;

        $this->eventManager
            ->expects($this->once())
            ->method('handle')
            ->with($this->callback(static fn (EventCommand $event): bool => $event->type === 'filecount_too_large'
                && str_contains($event->message, '950.000')
                && $event->level === 'critical'
                && $event->server === 'critical-server'
                && $event->context === []
                && $event->metric === null))
            ->willReturn([
                'eventRecorded' => true,
                'eventId' => 1,
            ])
        ;

        $this->monitor->collectServerHealthData($collector, 'critical-server');
    }

    public function testCollectServerHealthDataTriggersDiskUsageAlarm(): void
    {
        $collector = $this->createStub(ServerDataCollectorInterface::class);

        $collector->method('getServerSystemData')
            ->willReturn([
                'result' => [
                    'disk_space_used' => 95,
                    'disk_space_capacity' => 100,
                    'total_system_filecount' => 100,
                ],
            ])
        ;

        $this->useMockEventManager();

        $this->eventManager
            ->expects($this->exactly(4))
            ->method('getServerDataThresholdLevel')
            ->willReturnOnConsecutiveCalls('', '', '', 'alarm')
        ;

        $this->eventManager
            ->expects($this->once())
            ->method('getServerDataThresholdLimit')
            ->with('disk_space_usage_percent')
            ->willReturn(90)
        ;

        $this->eventManager
            ->expects($this->once())
            ->method('handle')
            ->with($this->callback(static fn (EventCommand $event): bool => $event->type === 'remaining_diskspace_too_low'
                && str_contains($event->message, '95')
                && $event->level === 'critical'
                && $event->server === 'disk-server'
                && $event->metric?->name === 'disk_space_usage_percent'
                && $event->metric->value === 95.0
                && $event->metric->threshold === 90))
            ->willReturn([
                'eventRecorded' => true,
                'eventId' => 10,
            ])
        ;

        $this->monitor->collectServerHealthData($collector, 'disk-server');
    }

    public function testCollectServerHealthDataReportsGoodCronjobsWithoutAnEvent(): void
    {
        $collector = $this->createStub(ServerDataCollectorInterface::class);
        $collector->method('getServerSystemData')->willReturn([
            'result' => [
                'disk_space_used' => 10,
                'disk_space_capacity' => 100,
                'total_system_filecount' => 100,
                'cronjobs' => ['total' => 3, 'working' => 3, 'stale' => 0, 'missing' => 0],
            ],
        ]);

        $this->useMockEventManager();
        $this->eventManager
            ->expects($this->exactly(5))
            ->method('getServerDataThresholdLevel')
            ->willReturnOnConsecutiveCalls('', '', '', '', 'good')
        ;
        $this->eventManager->expects($this->never())->method('handle');

        $payload = $this->monitor->collectServerHealthData($collector, 'cron-server');

        $this->assertSame('good', $payload['cronjobs']['level']);
        $this->assertSame('3/3', $payload['cronjobs']['count']);
    }

    public function testCollectServerHealthDataTriggersCronjobAlarmForFailedCronjobs(): void
    {
        $collector = $this->createStub(ServerDataCollectorInterface::class);
        $collector->method('getServerSystemData')->willReturn([
            'result' => [
                'disk_space_used' => 10,
                'disk_space_capacity' => 100,
                'total_system_filecount' => 100,
                'cronjobs' => ['total' => 3, 'working' => 2, 'stale' => 1, 'missing' => 0],
            ],
        ]);

        $this->useMockEventManager();
        $this->eventManager
            ->expects($this->exactly(5))
            ->method('getServerDataThresholdLevel')
            ->willReturnOnConsecutiveCalls('', '', '', '', 'alarm')
        ;
        $this->eventManager
            ->expects($this->once())
            ->method('getServerDataThresholdLimit')
            ->with('cronjobs_failed_count')
            ->willReturn(0)
        ;
        $this->eventManager
            ->expects($this->once())
            ->method('handle')
            ->with($this->callback(static fn (EventCommand $event): bool => $event->type === 'cronjobs_failing'
                && $event->message === 'Cronjobs failing (1/3, stale: 1, missing: 0) on Server: cron-server'
                && $event->level === 'critical'
                && $event->server === 'cron-server'
                && $event->metric?->name === 'cronjobs_failed_count'
                && $event->metric->value === 1
                && $event->metric->threshold === 0
                && $event->metric->additional === ['total' => 3, 'stale' => 1, 'missing' => 0]))
            ->willReturn(['eventRecorded' => true, 'eventId' => 1])
        ;

        $payload = $this->monitor->collectServerHealthData($collector, 'cron-server');

        $this->assertSame('alarm', $payload['cronjobs']['level']);
    }

    public function testCollectServerHealthDataTriggersCpuAndRamUsageAlarms(): void
    {
        $collector = $this->createStub(ServerDataCollectorInterface::class);
        $collector->method('getServerSystemData')->willReturn([
            'result' => [
                'cpu_usage' => 96,
                'ram_used' => 95,
                'ram_capacity' => 100,
                'disk_space_used' => 10,
                'disk_space_capacity' => 100,
                'total_system_filecount' => 100,
            ],
        ]);

        $this->useMockEventManager();
        $this->eventManager
            ->expects($this->exactly(4))
            ->method('getServerDataThresholdLevel')
            ->willReturnOnConsecutiveCalls('alarm', 'alarm', '', '')
        ;

        $events = [];
        $this->eventManager
            ->expects($this->exactly(2))
            ->method('handle')
            ->willReturnCallback(static function (...$arguments) use (&$events): array {
                $events[] = $arguments;

                return ['eventRecorded' => true, 'eventId' => 1];
            })
        ;

        $this->monitor->collectServerHealthData($collector, 'usage-server');

        $this->assertSame('cpu_usage_too_high', $events[0][0]->type);
        $this->assertSame('CPU usage critical (96 %) on Server: usage-server', $events[0][0]->message);
        $this->assertSame('ram_usage_too_high', $events[1][0]->type);
        $this->assertSame('RAM usage critical (95 %) on Server: usage-server', $events[1][0]->message);
    }

    public function testCollectServerHealthDataDoesNotTriggerCpuAndRamUsageEventsBelowAlarm(): void
    {
        $collector = $this->createStub(ServerDataCollectorInterface::class);
        $collector->method('getServerSystemData')->willReturn([
            'result' => [
                'cpu_usage' => 80,
                'ram_used' => 85,
                'ram_capacity' => 100,
                'disk_space_used' => 10,
                'disk_space_capacity' => 100,
                'total_system_filecount' => 100,
            ],
        ]);

        $this->useMockEventManager();
        $this->eventManager
            ->expects($this->exactly(4))
            ->method('getServerDataThresholdLevel')
            ->willReturnOnConsecutiveCalls('warning', 'critical', '', '')
        ;
        $this->eventManager->expects($this->never())->method('handle');

        $this->monitor->collectServerHealthData($collector, 'usage-server');
    }

    public function testCollectServerHealthDataThrowsWhenServerDataMissing(): void
    {
        $collector = $this->createStub(ServerDataCollectorInterface::class);
        $collector->method('getServerSystemData')->willReturn(['result' => null]);

        $this->expectException(Exception::class);

        $this->monitor->collectServerHealthData($collector, 'broken-server');
    }
}
