<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use Exception;
use Mata\Config\Monitoring\Section\ThresholdsConfig;
use Mata\Services\Cache\CacheType;
use Mata\Services\Collect\ServerDataCollectorInterface;
use Mata\Services\Event\Dto\EventCommand;
use Mata\Services\Event\EventManager;
use Mata\Services\Monitor\MetricThresholds;
use Mata\Services\Monitor\ServerMonitor;
use Mata\Tests\TestCase;
use Monolog\Handler\NullHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;

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
        $this->monitor = new ServerMonitor($logger, $this->eventManager, self::thresholds());
    }

    /** Limits the inputs below cross deliberately; thresholds.json defaults have no RAM limit. */
    private static function thresholds(): MetricThresholds
    {
        return new MetricThresholds([
            'cpu_usage' => ['warning' => 70, 'alarm' => 95],
            'ram_usage_percent' => ['warning' => 70, 'alarm' => 90],
            'total_system_filecount' => ['alarm' => 900000],
            'disk_space_usage_percent' => ['alarm' => 90],
            'cronjobs_failed_count' => ['good' => -1, 'alarm' => 0],
        ], []);
    }

    private function useMockEventManager(): void
    {
        $this->eventManager = $this->createMock(EventManager::class);
        $logger = new Logger('test');
        $logger->pushHandler(new NullHandler());
        $this->monitor = new ServerMonitor($logger, $this->eventManager, self::thresholds());
    }

    public function testCollectServerHealthDataReturnsStructuredPayload(): void
    {
        $collector = $this->createMock(ServerDataCollectorInterface::class);

        $collector->expects($this->once())
            ->method('getServerSystemData')
            ->with(CacheType::ServerData)
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
            ->expects($this->never())
            ->method('handle')
        ;

        $payload = $this->monitor->collectServerHealthData($collector, 'srv-1', CacheType::ServerData);

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

    /**
     * @param array<string,mixed> $overview
     * @param list<string>        $expectedEvents
     */
    #[DataProvider('databaseOverviews')]
    public function testDatabaseOverviewRaisesEventsOnlyForOutagesAndCrowdedServers(array $overview, array $expectedEvents): void
    {
        $collector = $this->createStub(ServerDataCollectorInterface::class);
        $collector->method('getDatabaseOverview')->willReturn($overview);

        $events = [];
        $eventManager = $this->createPartialMock(EventManager::class, ['handle']);
        $eventManager->expects($this->exactly(count($expectedEvents)))->method('handle')->willReturnCallback(static function (EventCommand $event) use (&$events): array {
            $events[] = $event->type;

            return ['eventRecorded' => true, 'eventId' => 1];
        });

        (new ServerMonitor(new Logger('test', [new NullHandler()]), $eventManager, MetricThresholds::fromConfig(ThresholdsConfig::default())))->evaluateDatabaseOverview($collector, 'db-server');

        $this->assertSame($expectedEvents, $events);
    }

    /**
     * @return iterable<string,array{array<string,mixed>,list<string>}>
     */
    public static function databaseOverviews(): iterable
    {
        $status = static fn (int $connections): array => ['result' => ['connections' => [['connection' => 'primary', 'error' => null, 'status' => ['connections' => $connections, 'max_connections' => 151]]]]];
        $nodeError = static fn (int $statusCode): array => ['result' => null, 'error' => ['message' => 'Node message', 'status_code' => $statusCode]];

        yield 'database server down' => [$nodeError(503), ['database_unreachable']];

        yield 'monitoring not configured on the node' => [$nodeError(404), []];

        yield 'connections near max_connections' => [$status(140), ['database_connections_too_high']];

        yield 'normal load' => [$status(40), []];

        $multiple = $status(80);
        $multiple['result']['connections'][] = ['connection' => 'Replica', 'error' => null, 'status' => ['connections' => 80, 'max_connections' => 151]];

        yield 'never sum counters' => [$multiple, []];

        $multiple['result']['connections'][] = ['connection' => 'Offline', 'status' => null, 'error' => ['status_code' => 503]];

        yield 'partial failure' => [$multiple, ['database_unreachable']];

        yield 'stale partial failure' => [$multiple + ['stale_cache' => true], []];
    }
}
