<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use GuzzleHttp\Promise\Create;
use Mata\Constants\CacheTypes;
use Mata\Constants\NodeHealthState;
use Mata\Models\ConfigSyncRepository;
use Mata\Services\Cache\CacheTypeResolver;
use Mata\Services\Collect\ServerDataCollectorInterface;
use Mata\Services\Event\EventManager;
use Mata\Services\Foundation\IdResolver;
use Mata\Services\Foundation\LogSizeEvaluator;
use Mata\Services\Monitor\AppErrorMonitorInterface;
use Mata\Services\Monitor\AppReachabilityMonitorInterface;
use Mata\Services\Monitor\Dto\ServerMonitoringServices;
use Mata\Services\Monitor\NodeHealthMonitorInterface;
use Mata\Services\Monitor\ServerMonitor;
use Mata\Services\Sync\AppInventorySync;
use Mata\Services\View\ServerViewBuilder;
use Mata\Utils\Tools;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * @covers \Mata\Services\View\ServerViewBuilder
 *
 * @internal
 */
final class ServerViewBuilderTest extends TestCase
{
    public function testBuildsServerDataFromOneServiceBundle(): void
    {
        $collector = $this->createMock(ServerDataCollectorInterface::class);
        $collector->expects($this->once())
            ->method('getApps')
            ->with(CacheTypes::APPS)
            ->willReturn([
                'result' => [],
                'cache_age' => 0,
                'cache_ttl' => 60,
            ]);

        $serverMonitor = $this->createMock(ServerMonitor::class);
        $serverMonitor->expects($this->once())
            ->method('collectServerHealthData')
            ->with($collector, 'server-a', CacheTypes::SERVER_DATA)
            ->willReturn(['cpu' => []]);

        $reachabilityMonitor = $this->createMock(AppReachabilityMonitorInterface::class);
        $reachabilityMonitor->expects($this->never())->method('checkMultipleEndpoints');

        $healthMonitor = $this->createMock(NodeHealthMonitorInterface::class);
        $healthMonitor->expects($this->once())
            ->method('checkAsync')
            ->with('server-a')
            ->willReturn(Create::promiseFor([
                'status' => 'healthy',
                'state' => NodeHealthState::HEALTHY,
            ]));

        $builder = new ServerViewBuilder(
            $serverMonitor,
            $this->logSizeEvaluator(),
            $this->createStub(EventManager::class),
            new Logger('test'),
            new CacheTypeResolver(),
        );

        $result = $builder->buildServersViewData(
            ['server-a' => new ServerMonitoringServices($collector, $reachabilityMonitor, $this->createStub(AppErrorMonitorInterface::class), $healthMonitor)],
            ['server-a' => ['title' => 'Server A']],
            false,
        );

        $this->assertSame('server-a', $result[0]['server']);
        $this->assertSame([], $result[0]['apps']);
        $this->assertSame(NodeHealthState::HEALTHY, $result[0]['health']['state']);
    }

    public function testCompactsSessionCountOnAppCards(): void
    {
        $builder = new ServerViewBuilder(
            $this->createStub(ServerMonitor::class),
            $this->logSizeEvaluator(),
            $this->createStub(EventManager::class),
            new Logger('test'),
            new CacheTypeResolver(),
        );
        $method = new ReflectionMethod($builder, 'formatSessionDataForView');

        $result = $method->invoke($builder, [
            'session' => [
                'type' => 'file',
                'status' => 'ok',
                'count_last_hour' => 236234,
                'count_last_day' => 236234,
                'count_total' => 236234,
                'path' => null,
                'size_kb' => null,
                'error' => null,
            ],
        ]);

        $this->assertSame('236k', $result['formatted_count']);
    }

    public function testBuildsAppLogViewDataWithoutRecordingEvents(): void
    {
        $tools = new class ('/tmp') extends Tools {
            public function formatKilobytes(int $kilobytes): string
            {
                return $kilobytes . ' KB';
            }
        };
        $events = $this->createMock(EventManager::class);
        $events->expects($this->once())
            ->method('getServerDataThresholdLevel')
            ->with(15000, 'log_filesize_kb')
            ->willReturn('alarm');
        $events->expects($this->never())->method('handle');
        $builder = new ServerViewBuilder(
            $this->createStub(ServerMonitor::class),
            new LogSizeEvaluator($tools, $events),
            $events,
            new Logger('test'),
            new CacheTypeResolver(),
        );

        $method = new ReflectionMethod($builder, 'buildAppLogViewData');
        $result = $method->invoke($builder, [
            'logs' => ['/var/log/app/error.log' => 5000, '/var/log/app/debug.log' => 15000],
            'status' => ['fatal' => 3, 'warnings' => 2, 'total' => 200],
        ], true);

        $this->assertSame('/var/log/app/debug.log', $result['log_analysis']['max_path']);
        $this->assertSame('15000 KB', $result['log_analysis']['max_size_formatted']);
        $this->assertSame('alarm', $result['log_analysis']['level']);
        $this->assertSame('errors', $result['error_analysis']['show_events_type']);
        $this->assertSame('alarms-enabled', $result['error_analysis']['alarms_enabled_class']);
    }

    public function testSkipsAppsWithPingDisabled(): void
    {
        $builder = new ServerViewBuilder(
            $this->createStub(ServerMonitor::class),
            $this->logSizeEvaluator(),
            $this->createStub(EventManager::class),
            new Logger('test'),
            new CacheTypeResolver(),
        );
        $reachabilityMonitor = $this->createMock(AppReachabilityMonitorInterface::class);
        $reachabilityMonitor->expects($this->once())
            ->method('checkMultipleEndpoints')
            ->willReturnCallback(function (array $targets): array {
                $this->assertSame(['enabled'], array_keys($targets));
                $this->assertSame('https://enabled.example.test', $targets['enabled']->url);

                return ['enabled' => ['reachable' => true]];
            });

        $method = new ReflectionMethod($builder, 'checkAppsAvailability');
        $result = $method->invoke($builder, [
            'enabled' => ['url' => 'https://enabled.example.test'],
            'disabled' => ['url' => 'https://disabled.example.test', 'ping' => 'off'],
        ], $reachabilityMonitor, 'server-a');

        $this->assertSame(['enabled' => ['reachable' => true]], $result);
    }

    public function testSyncsDiscoveredApplicationsOnForceRefresh(): void
    {
        $appPayload = $this->appPayload();

        $collector = $this->createMock(ServerDataCollectorInterface::class);
        $collector->expects($this->once())
            ->method('getApps')
            ->willReturn(['result' => ['shop' => $appPayload], 'cache_age' => 0, 'cache_ttl' => 60]);

        $serverMonitor = $this->createMock(ServerMonitor::class);
        $serverMonitor->expects($this->once())
            ->method('collectServerHealthData')
            ->willReturn(['cpu' => []]);

        $repository = $this->createMock(ConfigSyncRepository::class);
        $repository->expects($this->once())
            ->method('upsertApplication')
            ->with('shop', 'Shop', 'prod')
            ->willReturn(true);
        $sync = new AppInventorySync($repository, new IdResolver(), new Logger('test'));

        $builder = new ServerViewBuilder(
            $serverMonitor,
            $this->logSizeEvaluator(),
            $this->createStub(EventManager::class),
            new Logger('test'),
            new CacheTypeResolver(),
            $sync,
        );

        $result = $builder->buildServerViewData(
            'server-a',
            [],
            $collector,
            $this->createStub(AppReachabilityMonitorInterface::class),
            true,
        );

        $this->assertSame('shop', $result['apps'][0]['key']);
    }

    public function testSkipsApplicationSyncWithoutForceRefresh(): void
    {
        $repository = $this->createMock(ConfigSyncRepository::class);
        $repository->expects($this->never())->method('upsertApplication');
        $sync = new AppInventorySync($repository, new IdResolver(), new Logger('test'));

        $collector = $this->createStub(ServerDataCollectorInterface::class);
        $collector->method('getApps')->willReturn([
            'result' => ['shop' => $this->appPayload()],
            'cache_age' => 0,
            'cache_ttl' => 60,
        ]);

        $builder = new ServerViewBuilder(
            $this->createStub(ServerMonitor::class),
            $this->logSizeEvaluator(),
            $this->createStub(EventManager::class),
            new Logger('test'),
            new CacheTypeResolver(),
            $sync,
        );

        $result = $builder->buildServerViewData(
            'server-a',
            [],
            $collector,
            $this->createStub(AppReachabilityMonitorInterface::class),
            false,
        );

        $this->assertSame('shop', $result['apps'][0]['key']);
    }

    public function testMissingBundleBuildsAnErrorCard(): void
    {
        $builder = new ServerViewBuilder(
            $this->createStub(ServerMonitor::class),
            $this->logSizeEvaluator(),
            $this->createStub(EventManager::class),
            new Logger('test'),
            new CacheTypeResolver(),
        );

        $result = $builder->buildServersViewData([], ['server-a' => []], false);

        $this->assertSame('Monitoring services unavailable for server server-a', $result[0]['error']);
        $this->assertSame(NodeHealthState::UNKNOWN, $result[0]['health']['state']);
    }

    private function logSizeEvaluator(): LogSizeEvaluator
    {
        $events = $this->createStub(EventManager::class);
        $events->method('getServerDataThresholdLevel')->willReturn('good');

        return new LogSizeEvaluator(new Tools('/tmp'), $events);
    }

    /** @return array<string,mixed> */
    private function appPayload(): array
    {
        return [
            'title' => 'Shop',
            'env' => 'prod',
            'url' => 'https://shop.example.test',
            'ping' => 'off',
            'logs' => [],
            'status' => ['fatal' => 0, 'warnings' => 0, 'total' => 0],
            'session' => [
                'type' => 'file',
                'status' => 'unconfigured',
                'count_last_hour' => null,
                'count_last_day' => null,
                'count_total' => null,
                'path' => null,
                'size_kb' => null,
                'error' => null,
            ],
        ];
    }
}
