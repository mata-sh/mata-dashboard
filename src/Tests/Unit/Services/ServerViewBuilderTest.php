<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use GuzzleHttp\Promise\Create;
use Mata\Constants\CacheTypes;
use Mata\Constants\NodeHealthState;
use Mata\Services\Cache\CacheTypeResolver;
use Mata\Services\Collect\ServerDataCollectorInterface;
use Mata\Services\Event\EventManager;
use Mata\Services\Foundation\LogAnalyzer;
use Mata\Services\Monitor\AppMonitorInterface;
use Mata\Services\Monitor\Dto\ServerDashboardServices;
use Mata\Services\Monitor\NodeHealthMonitorInterface;
use Mata\Services\Monitor\ServerMonitor;
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

        $appMonitor = $this->createMock(AppMonitorInterface::class);
        $appMonitor->expects($this->never())->method('checkMultipleEndpoints');

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
            $this->createStub(LogAnalyzer::class),
            $this->createStub(EventManager::class),
            new Logger('test'),
            new CacheTypeResolver(),
            new Tools(__DIR__),
        );

        $result = $builder->buildServersViewData(
            ['server-a' => new ServerDashboardServices($collector, $appMonitor, $healthMonitor)],
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
            $this->createStub(LogAnalyzer::class),
            $this->createStub(EventManager::class),
            new Logger('test'),
            new CacheTypeResolver(),
            new Tools(__DIR__),
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

    public function testSkipsAppsWithPingDisabled(): void
    {
        $builder = new ServerViewBuilder(
            $this->createStub(ServerMonitor::class),
            $this->createStub(LogAnalyzer::class),
            $this->createStub(EventManager::class),
            new Logger('test'),
            new CacheTypeResolver(),
            new Tools(__DIR__),
        );
        $appMonitor = $this->createMock(AppMonitorInterface::class);
        $appMonitor->expects($this->once())
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
        ], $appMonitor, 'server-a');

        $this->assertSame(['enabled' => ['reachable' => true]], $result);
    }

    public function testMissingBundleBuildsAnErrorCard(): void
    {
        $builder = new ServerViewBuilder(
            $this->createStub(ServerMonitor::class),
            $this->createStub(LogAnalyzer::class),
            $this->createStub(EventManager::class),
            new Logger('test'),
            new CacheTypeResolver(),
            new Tools(__DIR__),
        );

        $result = $builder->buildServersViewData([], ['server-a' => []], false);

        $this->assertSame('Monitoring services unavailable for server server-a', $result[0]['error']);
        $this->assertSame(NodeHealthState::UNKNOWN, $result[0]['health']['state']);
    }
}
