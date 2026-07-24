<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use Mata\Models\ConfigSyncRepository;
use Mata\Services\Collect\ServerDataCollectorInterface;
use Mata\Services\Cron\ServerDataFetchRunner;
use Mata\Services\Identity\ServerApplicationIdResolver;
use Mata\Services\Monitor\AppErrorMonitorInterface;
use Mata\Services\Monitor\AppReachabilityMonitorInterface;
use Mata\Services\Monitor\Dto\AppErrorMonitoringResult;
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
        $collector->expects($this->once())->method('getServerSystemData')->with(true);
        $collector->expects($this->once())->method('getApps')->with(true)->willReturn(['result' => [
            'shop' => ['title' => 'Shop', 'env' => 'prod', 'url' => 'https://shop.example.test', 'ping' => 'off'],
        ]]);
        $collector->expects($this->never())->method('fetchAppLogs');
        $collector->expects($this->never())->method('fetchAppLogsStatus');

        $health = $this->createMock(NodeHealthMonitorInterface::class);
        $health->expects($this->once())->method('checkSynchronously')->with('srv', true);
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
            $this->createStub(NodeHealthMonitorInterface::class),
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
            $this->createStub(NodeHealthMonitorInterface::class),
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
            $this->createStub(NodeHealthMonitorInterface::class),
            $this->inventorySync(),
        );

        $this->assertFalse($result->appsFetched);
    }

    private function inventorySync(): AppInventorySync
    {
        $db = new SqliteTestDatabase();
        $db->pdo()->exec('CREATE TABLE applications (id INTEGER PRIMARY KEY, code TEXT UNIQUE, display_name TEXT, app_env TEXT, created_at TEXT, updated_at TEXT); CREATE TABLE servers (id INTEGER PRIMARY KEY, name TEXT)');
        $repository = new ConfigSyncRepository($db);

        return new AppInventorySync($repository, new ServerApplicationIdResolver($repository), new NullLogger());
    }
}
