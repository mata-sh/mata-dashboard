<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use Mata\Constants\CacheTypes;
use Mata\Constants\NodeHealthState;
use Mata\Services\Cache\ApiCacheKeyFactory;
use Mata\Services\Cache\CacheEntry;
use Mata\Services\Cache\CacheStoreInterface;
use Mata\Services\Cache\CacheTtlResolver;
use Mata\Services\Event\EventManager;
use Mata\Services\Foundation\ApiClient;
use Mata\Services\Monitor\NodeHealthMonitor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \Mata\Services\Monitor\NodeHealthMonitor
 *
 * @internal
 */
final class NodeHealthMonitorTest extends TestCase
{
    /**
     * @param array<string,mixed> $health
     */
    #[DataProvider('healthResults')]
    public function testDerivesState(array $health, string $state): void
    {
        $store = $this->createMock(CacheStoreInterface::class);
        $store->method('get')->willReturn(null);
        $store->expects($this->once())->method('put')->willReturn(true);
        $client = $this->createMock(ApiClient::class);
        $client->method('getApiBase')->willReturn('https://node.example.test/api');
        $client->method('getHealth')->willReturn($health);
        $events = $this->createMock(EventManager::class);
        $events->method('handleEvent')->willReturn(['eventRecorded' => false, 'eventId' => null]);

        $monitor = new NodeHealthMonitor(
            $store,
            new ApiCacheKeyFactory(),
            new CacheTtlResolver([CacheTypes::HEALTH => 60]),
            $client,
            $events,
            new NullLogger(),
        );

        $this->assertSame($state, $monitor->check('node-a')['state']);
    }

    /**
     * @return iterable<string,array{array<string,mixed>,string}>
     */
    public static function healthResults(): iterable
    {
        yield 'healthy response' => [[
            'status' => 'healthy',
            'maintenance_mode' => false,
        ], NodeHealthState::HEALTHY];

        yield 'maintenance response' => [[
            'status' => 'healthy',
            'maintenance_mode' => true,
        ], NodeHealthState::MAINTENANCE];

        yield 'unhealthy 503 response' => [[
            'status' => 'unhealthy',
            'httpCode' => 503,
            'maintenance_mode' => false,
        ], NodeHealthState::UNHEALTHY];

        yield 'unreachable exception response' => [[
            'status' => 'unreachable',
            'error' => 'Connection refused',
            'maintenance_mode' => null,
        ], NodeHealthState::UNREACHABLE];
    }

    public function testUsesCachedHealthResult(): void
    {
        $store = $this->createMock(CacheStoreInterface::class);
        $store->expects($this->once())
            ->method('get')
            ->willReturn(new CacheEntry([
                'status' => 'healthy',
                'maintenance_mode' => false,
                'state' => NodeHealthState::HEALTHY,
            ], time()));
        $store->expects($this->never())->method('put');
        $client = $this->createMock(ApiClient::class);
        $client->method('getApiBase')->willReturn('https://node.example.test/api');
        $client->expects($this->never())->method('getHealth');

        $monitor = new NodeHealthMonitor(
            $store,
            new ApiCacheKeyFactory(),
            new CacheTtlResolver([CacheTypes::HEALTH => 60]),
            $client,
            $this->createMock(EventManager::class),
            new NullLogger(),
        );

        $this->assertSame(NodeHealthState::HEALTHY, $monitor->check('node-a')['state']);
    }

    public function testEmitsUnhealthyWarningEvent(): void
    {
        $store = $this->createMock(CacheStoreInterface::class);
        $store->method('get')->willReturn(null);
        $store->method('put')->willReturn(true);
        $client = $this->createMock(ApiClient::class);
        $client->method('getApiBase')->willReturn('https://node.example.test/api');
        $client->method('getHealth')->willReturn([
            'status' => 'unhealthy',
            'httpCode' => 503,
            'maintenance_mode' => false,
        ]);
        $events = $this->createMock(EventManager::class);
        $events->expects($this->once())
            ->method('handleEvent')
            ->with('node_unhealthy', $this->isType('string'), ['server' => 'node-a'], 'warning', 'node-a')
            ->willReturn(['eventRecorded' => true, 'eventId' => 1]);

        $monitor = new NodeHealthMonitor(
            $store,
            new ApiCacheKeyFactory(),
            new CacheTtlResolver([CacheTypes::HEALTH => 60]),
            $client,
            $events,
            new NullLogger(),
        );

        $monitor->check('node-a');
    }

    public function testEmitsUnreachableCriticalEvent(): void
    {
        $store = $this->createMock(CacheStoreInterface::class);
        $store->method('get')->willReturn(null);
        $store->method('put')->willReturn(true);
        $client = $this->createMock(ApiClient::class);
        $client->method('getApiBase')->willReturn('https://node.example.test/api');
        $client->method('getHealth')->willReturn([
            'status' => 'unreachable',
            'error' => 'Connection refused',
            'maintenance_mode' => null,
        ]);
        $events = $this->createMock(EventManager::class);
        $events->expects($this->once())
            ->method('handleEvent')
            ->with('node_unreachable', $this->isType('string'), ['server' => 'node-a'], 'critical', 'node-a')
            ->willReturn(['eventRecorded' => true, 'eventId' => 1]);

        $monitor = new NodeHealthMonitor(
            $store,
            new ApiCacheKeyFactory(),
            new CacheTtlResolver([CacheTypes::HEALTH => 60]),
            $client,
            $events,
            new NullLogger(),
        );

        $monitor->check('node-a');
    }
}
