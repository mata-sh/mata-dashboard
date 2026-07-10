<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

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
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

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
            ->method('check')
            ->with('server-a')
            ->willReturn([
                'status' => 'healthy',
                'state' => NodeHealthState::HEALTHY,
            ]);

        $builder = new ServerViewBuilder(
            $serverMonitor,
            $this->createMock(LogAnalyzer::class),
            $this->createMock(EventManager::class),
            new Logger('test'),
            new CacheTypeResolver(),
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

    public function testMissingBundleBuildsAnErrorCard(): void
    {
        $builder = new ServerViewBuilder(
            $this->createMock(ServerMonitor::class),
            $this->createMock(LogAnalyzer::class),
            $this->createMock(EventManager::class),
            new Logger('test'),
            new CacheTypeResolver(),
        );

        $result = $builder->buildServersViewData([], ['server-a' => []], false);

        $this->assertSame('Monitoring services unavailable for server server-a', $result[0]['error']);
        $this->assertSame(NodeHealthState::UNKNOWN, $result[0]['health']['state']);
    }
}
