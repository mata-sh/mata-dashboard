<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use Exception;
use Mata\Services\Collect\ServerDataCollectorInterface;
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
            ->method('handleEvent')
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
            ->method('handleEvent')
            ->with(
                'filecount_too_large',
                $this->callback(static fn (string $message): bool => str_contains($message, '950.000')),
                [],
                'critical',
                'critical-server',
                null,
                false
            )
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
            ->method('handleEvent')
            ->with(
                'remaining_diskspace_too_low',
                $this->callback(static fn (string $message): bool => str_contains($message, '95')),
                [],
                'critical',
                'disk-server',
                null,
                false
            )
            ->willReturn([
                'eventRecorded' => true,
                'eventId' => 10,
            ])
        ;

        $this->monitor->collectServerHealthData($collector, 'disk-server');
    }

    public function testCollectServerHealthDataThrowsWhenServerDataMissing(): void
    {
        $collector = $this->createStub(ServerDataCollectorInterface::class);
        $collector->method('getServerSystemData')->willReturn(['result' => null]);

        $this->expectException(Exception::class);

        $this->monitor->collectServerHealthData($collector, 'broken-server');
    }
}
