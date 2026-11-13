<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use Mata\Models\Sync\ConfigSyncRepository;
use Mata\Services\Cache\CacheType;
use Mata\Services\Collect\ServerDataCollectorInterface;
use Mata\Services\Cron\ServerDataFetchRunner;
use Mata\Services\Identity\ServerApplicationIdResolver;
use Mata\Services\Monitor\AppErrorMonitorInterface;
use Mata\Services\Monitor\AppReachabilityMonitorInterface;
use Mata\Services\Monitor\Dto\AppErrorMonitoringResult;
use Mata\Services\Monitor\Dto\NodeHealthResult;
use Mata\Services\Monitor\NodeHealthMonitorInterface;
use Mata\Services\Sync\AppInventorySync;
use Mata\Tests\Helpers\SqliteTestDatabase;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \Mata\Services\Cron\ServerDataFetchRunner
 *
 * @internal
 */
final class ServerDataFetchRunnerTest extends TestCase
{
    public function testUsesOneForcedAppsResponseAndNeverFetchesDetailedLogs(): void
    {
        $collector = $this->createMock(ServerDataCollectorInterface::class);
        $collector->expects($this->once())->method('getServerSystemData')->with(CacheType::ServerData, true);
        $collector->expects($this->once())->method('getApps')->with(CacheType::Apps, true)->willReturn(['result' => [
            'shop' => [
                'title' => 'Shop', 'env' => 'prod', 'url' => 'https://shop.example.test',
                'monitoring' => ['reachability' => false],
            ],
        ]]);
        $collector->expects($this->never())->method('fetchAppLogs');
        $collector->expects($this->never())->method('fetchAppLogsStatus');

        $health = $this->createMock(NodeHealthMonitorInterface::class);
        $health->expects($this->once())->method('checkSynchronously')->with('srv', CacheType::Health, true)
            ->willReturn($this->healthyNodeResult());
        $reachabilityMonitor = $this->createMock(AppReachabilityMonitorInterface::class);
        $reachabilityMonitor->expects($this->never())->method('checkMultipleEndpoints');
        $errorMonitor = $this->createMock(AppErrorMonitorInterface::class);
        $errorMonitor->expects($this->once())->method('monitor')->with('srv', $this->isArray())
            ->willReturn(AppErrorMonitoringResult::empty(1));

        $result = (new ServerDataFetchRunner(new NullLogger()))->run(
            'srv',
            $collector,
            $reachabilityMonitor,
            $errorMonitor,
            $health,
            $this->inventorySync(),
        );

        $this->assertTrue($result->appsFetched);
        $this->assertSame(1, $result->applicationsSynced);
    }

    public function testMonitorFailureDoesNotPreventAvailabilityProbes(): void
    {
        $collector = $this->createStub(ServerDataCollectorInterface::class);
        $collector->method('getApps')->willReturn(['result' => [
            'shop' => ['title' => 'Shop', 'env' => 'prod', 'url' => 'https://shop.example.test'],
        ]]);
        $errorMonitor = $this->createMock(AppErrorMonitorInterface::class);
        $errorMonitor->expects($this->once())->method('monitor')->willThrowException(new RuntimeException('broken'));
        $reachabilityMonitor = $this->createMock(AppReachabilityMonitorInterface::class);
        $reachabilityMonitor->expects($this->once())->method('checkMultipleEndpoints')->willReturn([]);

        $result = (new ServerDataFetchRunner(new NullLogger()))->run(
            'srv',
            $collector,
            $reachabilityMonitor,
            $errorMonitor,
            $this->healthMonitor(),
            $this->inventorySync(),
        );

        $this->assertSame(1, $result->errorMonitoring->failures);
        $this->assertSame(1, $result->availabilityTargets);
    }

    public function testInvalidAppsResponseSkipsErrorMonitoringAndAvailabilityProbes(): void
    {
        $collector = $this->createStub(ServerDataCollectorInterface::class);
        $collector->method('getApps')->willReturn(null);
        $errorMonitor = $this->createMock(AppErrorMonitorInterface::class);
        $errorMonitor->expects($this->never())->method('monitor');
        $reachabilityMonitor = $this->createMock(AppReachabilityMonitorInterface::class);
        $reachabilityMonitor->expects($this->never())->method('checkMultipleEndpoints');

        $result = (new ServerDataFetchRunner(new NullLogger()))->run(
            'srv',
            $collector,
            $reachabilityMonitor,
            $errorMonitor,
            $this->healthMonitor(),
            $this->inventorySync(),
        );

        $this->assertFalse($result->appsFetched);
    }

    public function testNonArrayAppsResultSkipsErrorMonitoringAndAvailabilityProbes(): void
    {
        $collector = $this->createStub(ServerDataCollectorInterface::class);
        $collector->method('getApps')->willReturn(['result' => 'invalid']);
        $errorMonitor = $this->createMock(AppErrorMonitorInterface::class);
        $errorMonitor->expects($this->never())->method('monitor');
        $reachabilityMonitor = $this->createMock(AppReachabilityMonitorInterface::class);
        $reachabilityMonitor->expects($this->never())->method('checkMultipleEndpoints');

        $result = (new ServerDataFetchRunner(new NullLogger()))->run(
            'srv',
            $collector,
            $reachabilityMonitor,
            $errorMonitor,
            $this->healthMonitor(),
            $this->inventorySync(),
        );

        $this->assertFalse($result->appsFetched);
    }

    public function testOnlyReachabilityEnabledAppsBecomeAvailabilityTargets(): void
    {
        $collector = $this->createStub(ServerDataCollectorInterface::class);
        $collector->method('getApps')->willReturn(['result' => [
            'shop' => ['title' => 'Shop', 'url' => 'https://shop.example.test'],
            'admin' => [
                'title' => 'Admin', 'url' => 'https://admin.example.test',
                'monitoring' => ['reachability' => false],
            ],
        ]]);
        $errorMonitor = $this->createStub(AppErrorMonitorInterface::class);
        $errorMonitor->method('monitor')->willReturn(AppErrorMonitoringResult::empty(2));
        $reachabilityMonitor = $this->createMock(AppReachabilityMonitorInterface::class);
        $reachabilityMonitor->expects($this->once())
            ->method('checkMultipleEndpoints')
            ->with($this->callback(static fn (array $targets): bool => array_keys($targets) === ['shop']))
            ->willReturn([]);

        $result = (new ServerDataFetchRunner(new NullLogger()))->run(
            'srv',
            $collector,
            $reachabilityMonitor,
            $errorMonitor,
            $this->healthMonitor(),
            $this->inventorySync(),
        );

        $this->assertSame(1, $result->availabilityTargets);
    }

    public function testUnreachableNodeSkipsEveryRemainingRequestForThisCycle(): void
    {
        $collector = $this->createMock(ServerDataCollectorInterface::class);
        $collector->expects($this->never())->method('getServerSystemData');
        $collector->expects($this->never())->method('getApps');
        $errorMonitor = $this->createMock(AppErrorMonitorInterface::class);
        $errorMonitor->expects($this->never())->method('monitor');
        $reachabilityMonitor = $this->createMock(AppReachabilityMonitorInterface::class);
        $reachabilityMonitor->expects($this->never())->method('checkMultipleEndpoints');

        $health = $this->createStub(NodeHealthMonitorInterface::class);
        $health->method('checkSynchronously')->willReturn(NodeHealthResult::fromArray(['status' => 'unreachable']));

        $result = (new ServerDataFetchRunner(new NullLogger()))->run(
            'srv',
            $collector,
            $reachabilityMonitor,
            $errorMonitor,
            $health,
            $this->inventorySync(),
        );

        $this->assertTrue($result->nodeUnreachable);
        $this->assertFalse($result->appsFetched);
    }

    public function testUnhealthyNodeStillGetsFetchedBecauseItAnswered(): void
    {
        $collector = $this->createMock(ServerDataCollectorInterface::class);
        $collector->expects($this->once())->method('getApps')->willReturn(['result' => [
            'shop' => ['title' => 'Shop', 'url' => 'https://shop.example.test'],
        ]]);
        $errorMonitor = $this->createStub(AppErrorMonitorInterface::class);
        $errorMonitor->method('monitor')->willReturn(AppErrorMonitoringResult::empty(1));
        $reachabilityMonitor = $this->createStub(AppReachabilityMonitorInterface::class);
        $reachabilityMonitor->method('checkMultipleEndpoints')->willReturn([]);

        $health = $this->createStub(NodeHealthMonitorInterface::class);
        $health->method('checkSynchronously')->willReturn(NodeHealthResult::fromArray(['status' => 'unhealthy']));

        $result = (new ServerDataFetchRunner(new NullLogger()))->run(
            'srv',
            $collector,
            $reachabilityMonitor,
            $errorMonitor,
            $health,
            $this->inventorySync(),
        );

        $this->assertFalse($result->nodeUnreachable);
        $this->assertTrue($result->appsFetched);
    }

    private function healthMonitor(): NodeHealthMonitorInterface
    {
        $monitor = $this->createStub(NodeHealthMonitorInterface::class);
        $monitor->method('checkSynchronously')->willReturn($this->healthyNodeResult());

        return $monitor;
    }

    private function healthyNodeResult(): NodeHealthResult
    {
        return NodeHealthResult::fromArray(['status' => 'healthy']);
    }

    private function inventorySync(): AppInventorySync
    {
        $db = new SqliteTestDatabase();
        $db->pdo()->exec('CREATE TABLE applications (id INTEGER PRIMARY KEY, code TEXT UNIQUE, display_name TEXT, app_env TEXT, created_at TEXT, updated_at TEXT); CREATE TABLE servers (id INTEGER PRIMARY KEY, name TEXT)');
        $repository = new ConfigSyncRepository($db);

        return new AppInventorySync($repository, new ServerApplicationIdResolver($repository), new NullLogger());
    }
}
