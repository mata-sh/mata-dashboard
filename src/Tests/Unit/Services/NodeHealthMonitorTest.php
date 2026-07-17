<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Mata\Constants\CacheTypes;
use Mata\Constants\NodeHealthState;
use Mata\Services\Cache\ApiCacheKeyFactory;
use Mata\Services\Cache\CacheEntry;
use Mata\Services\Cache\CacheStoreInterface;
use Mata\Services\Cache\CacheTtlResolver;
use Mata\Services\Event\EventManager;
use Mata\Services\Foundation\ApiClient;
use Mata\Services\Foundation\ProbeClient;
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
    /** @param array<string,mixed> $health */
    #[DataProvider('healthResults')]
    public function testDerivesState(array $health, string $state): void
    {
        $store = $this->createMock(CacheStoreInterface::class);
        $store->method('get')->willReturn(null);
        $store->expects($this->once())->method('put')->willReturn(true);
        $monitor = $this->monitor($store, $this->probe($health));

        $this->assertSame($state, $monitor->checkOrWait('node-a')['state']);
    }

    /** @return iterable<string,array{array<string,mixed>,string}> */
    public static function healthResults(): iterable
    {
        yield 'healthy response' => [['status' => 'healthy', 'maintenance_mode' => false], NodeHealthState::HEALTHY];

        yield 'maintenance response' => [['status' => 'healthy', 'maintenance_mode' => true], NodeHealthState::MAINTENANCE];

        yield 'unhealthy 503 response' => [['status' => 'unhealthy', 'httpCode' => 503, 'maintenance_mode' => false], NodeHealthState::UNHEALTHY];
    }

    public function testUsesCachedHealthResult(): void
    {
        $store = $this->createMock(CacheStoreInterface::class);
        $store->expects($this->once())->method('get')->willReturn(new CacheEntry([
            'status' => 'healthy', 'maintenance_mode' => false, 'state' => NodeHealthState::HEALTHY,
        ], time()));
        $store->expects($this->never())->method('put');

        $this->assertSame(NodeHealthState::HEALTHY, $this->monitor($store, new ProbeClient())->checkOrWait('node-a')['state']);
    }

    public function testForcedRefreshBypassesCachedHealth(): void
    {
        $store = $this->createMock(CacheStoreInterface::class);
        $store->expects($this->never())->method('get');
        $store->expects($this->once())->method('put')->willReturn(true);
        $monitor = $this->monitor($store, $this->probe(['status' => 'healthy', 'maintenance_mode' => false]));

        $health = $monitor->checkOrWait('node-a', forceRefresh: true);

        $this->assertSame(NodeHealthState::HEALTHY, $health['state']);
    }

    public function testAsyncCompletionCachesAndEmitsUnhealthyEvent(): void
    {
        $store = $this->createMock(CacheStoreInterface::class);
        $store->method('get')->willReturn(null);
        $store->expects($this->once())->method('put')->willReturn(true);
        $events = $this->createMock(EventManager::class);
        $events->expects($this->once())
            ->method('handleEvent')
            ->with('node_unhealthy', $this->isType('string'), ['server' => 'node-a'], 'warning', 'node-a')
            ->willReturn([]);
        $monitor = $this->monitor($store, $this->probe(['status' => 'unhealthy', 'maintenance_mode' => false]), $events);

        $pending = $monitor->checkAsync('node-a');
        $this->assertInstanceOf(PromiseInterface::class, $pending);
        $this->assertSame(NodeHealthState::UNHEALTHY, $pending->wait()['state']);
    }

    private function monitor(CacheStoreInterface $store, ProbeClient $probe, ?EventManager $events = null): NodeHealthMonitor
    {
        $apiClient = $this->createMock(ApiClient::class);
        $apiClient->method('getApiBase')->willReturn('https://node.example.test/api');

        return new NodeHealthMonitor(
            $store,
            new ApiCacheKeyFactory(),
            new CacheTtlResolver([CacheTypes::HEALTH => 60]),
            $apiClient,
            $probe,
            $events ?? $this->createMock(EventManager::class),
            new NullLogger(),
        );
    }

    /** @param array<string,mixed> $health */
    private function probe(array $health): ProbeClient
    {
        return new ProbeClient(true, new Client([
            'handler' => HandlerStack::create(new MockHandler([
                new Response($health['httpCode'] ?? 200, [], json_encode($health, JSON_THROW_ON_ERROR)),
            ])),
        ]));
    }
}
