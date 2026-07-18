<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Mata\Constants\CacheTypes;
use Mata\Services\Cache\ApiCacheKeyFactory;
use Mata\Services\Cache\CacheEntry;
use Mata\Services\Cache\CacheStoreInterface;
use Mata\Services\Cache\CacheTtlResolver;
use Mata\Services\Event\EventManager;
use Mata\Services\Foundation\ApiClient;
use Mata\Services\Foundation\ProbeClient;
use Mata\Services\Monitor\AppMonitor;
use Mata\Services\Monitor\Dto\EndpointTarget;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \Mata\Services\Monitor\AppMonitor
 *
 * @internal
 */
final class AppMonitorTest extends TestCase
{
    public function testUsesCachedAvailabilityResult(): void
    {
        $store = $this->createMock(CacheStoreInterface::class);
        $store->expects($this->once())->method('get')->willReturn(new CacheEntry(['reachable' => true], time() - 1));
        $store->expects($this->never())->method('put');

        $this->assertSame(['reachable' => true], $this->monitor($store, new ProbeClient())->checkEndpoint(
            new EndpointTarget('app', 'https://app.example.test')
        ));
    }

    public function testTtlZeroStillProbesAndWritesThroughToStore(): void
    {
        $store = $this->createMock(CacheStoreInterface::class);
        $store->expects($this->once())->method('get')->with($this->isString(), 0)->willReturn(null);
        $store->expects($this->once())->method('put')->with($this->isString(), $this->isArray())->willReturn(true);
        $probe = $this->probe(new Response(503));

        $result = $this->monitor($store, $probe, CacheTypes::NONE)->checkEndpoint(
            new EndpointTarget('app', 'https://app.example.test'),
            2,
            CacheTypes::NONE
        );

        $this->assertFalse($result['reachable']);
        $this->assertSame(503, $result['httpCode']);
    }

    public function testForcedBatchBypassesCacheAndWritesTheNormalPingKey(): void
    {
        $target = new EndpointTarget('app', 'https://app.example.test');
        $cacheKey = (new ApiCacheKeyFactory())->createAvailabilityKey(
            'https://node.example.test/api',
            CacheTypes::PING,
            $target->resolveProbeUrl(),
            2,
        );
        $store = $this->createMock(CacheStoreInterface::class);
        $store->expects($this->never())->method('get');
        $store->expects($this->once())
            ->method('put')
            ->with($cacheKey, $this->isArray())
            ->willReturn(true);

        $results = $this->monitor($store, $this->probe(new Response(200)))->checkMultipleEndpoints(
            ['app' => $target],
            forceRefresh: true,
        );

        $this->assertTrue($results['app']['reachable']);
    }

    public function testStatusEndpointMaintenanceDoesNotRecordUnreachableEvent(): void
    {
        $store = $this->createMock(CacheStoreInterface::class);
        $store->method('get')->willReturn(null);
        $store->expects($this->once())->method('put');
        $events = $this->createMock(EventManager::class);
        $events->expects($this->never())->method('handleEvent');
        $monitor = $this->monitor($store, $this->probe(new Response(503, [], '{"maintenance_mode":true}')), CacheTypes::PING, $events);

        $result = $monitor->checkMultipleEndpoints([
            'app' => new EndpointTarget(
                identifier: 'app',
                url: 'https://app.example.test',
                statusUrl: 'https://app.example.test/status.php',
            ),
        ]);

        $this->assertFalse($result['app']['reachable']);
        $this->assertTrue($result['app']['maintenance_mode']);
        $this->assertSame('https://app.example.test/status.php', $result['app']['url']);
    }

    public function testCachedMaintenanceDoesNotRecordUnreachableEvent(): void
    {
        $store = $this->createStub(CacheStoreInterface::class);
        $store->method('get')->willReturn(new CacheEntry([
            'reachable' => false,
            'maintenance_mode' => true,
            'httpCode' => 503,
        ], time()));
        $events = $this->createMock(EventManager::class);
        $events->expects($this->never())->method('handleEvent');
        $monitor = $this->monitor($store, new ProbeClient(), CacheTypes::PING, $events);

        $result = $monitor->checkMultipleEndpoints([
            'app' => new EndpointTarget('app', 'https://app.example.test'),
        ]);

        $this->assertTrue($result['app']['maintenance_mode']);
    }

    public function testMixedCacheBatchPreservesOrderAndRecordsCachedFailures(): void
    {
        $store = $this->createMock(CacheStoreInterface::class);
        $store->method('get')->willReturnOnConsecutiveCalls(
            new CacheEntry(['reachable' => false, 'httpCode' => null, 'error' => 'cached'], time()),
            null,
            null,
        );
        $store->expects($this->exactly(2))->method('put');
        $events = $this->createMock(EventManager::class);
        $events->expects($this->exactly(2))->method('handleEvent')->willReturn([]);
        $monitor = $this->monitor($store, $this->probe(new Response(200), new Response(503)), CacheTypes::PING, $events);

        $results = $monitor->checkMultipleEndpoints([
            'cached' => new EndpointTarget('cached', 'https://cached.example.test'),
            'healthy' => new EndpointTarget('healthy', 'https://healthy.example.test'),
            'down' => new EndpointTarget('down', 'https://down.example.test'),
        ]);

        $this->assertSame(['cached', 'healthy', 'down'], array_keys($results));
        $this->assertFalse($results['cached']['reachable']);
        $this->assertTrue($results['healthy']['reachable']);
        $this->assertFalse($results['down']['reachable']);
    }

    public function testMissingProbeResultIsReportedForItsApplication(): void
    {
        $store = $this->createMock(CacheStoreInterface::class);
        $store->method('get')->willReturn(null);
        $store->expects($this->once())->method('put');

        $probe = $this->createStub(ProbeClient::class);
        $probe->method('checkAvailabilityAsync')->willReturn(Create::promiseFor([]));
        $probe->method('settle')->willReturn([]);

        $events = $this->createMock(EventManager::class);
        $events->expects($this->once())->method('handleEvent')->willReturn([]);
        $monitor = $this->monitor($store, $probe, CacheTypes::PING, $events);

        $results = $monitor->checkMultipleEndpoints([
            'app' => new EndpointTarget('app', 'https://app.example.test'),
        ]);

        $this->assertFalse($results['app']['reachable']);
        $this->assertSame('Availability probe did not return a result', $results['app']['error']);
    }

    public function testLimitsUncachedProbesToFourPerBatch(): void
    {
        $store = $this->createMock(CacheStoreInterface::class);
        $store->method('get')->willReturn(null);
        $store->expects($this->exactly(10))->method('put');

        $probe = $this->createMock(ProbeClient::class);
        $probe->expects($this->exactly(10))
            ->method('checkAvailabilityAsync')
            ->willReturnCallback(static fn (): PromiseInterface => Create::promiseFor([]));
        $batchSizes = [];
        $probe->expects($this->exactly(3))
            ->method('settle')
            ->willReturnCallback(static function (array $promises) use (&$batchSizes): array {
                $batchSizes[] = count($promises);

                return array_map(
                    static fn (): array => ['reachable' => true, 'maintenance_mode' => false],
                    $promises
                );
            });

        $events = $this->createMock(EventManager::class);
        $events->expects($this->never())->method('handleEvent');
        $monitor = $this->monitor($store, $probe, CacheTypes::PING, $events);
        $targets = [];
        for ($index = 1; $index <= 10; ++$index) {
            $targets["app-{$index}"] = new EndpointTarget("app-{$index}", "https://app-{$index}.example.test");
        }

        $results = $monitor->checkMultipleEndpoints($targets);

        $this->assertSame([4, 4, 2], $batchSizes);
        $this->assertSame(array_keys($targets), array_keys($results));
    }

    private function monitor(
        CacheStoreInterface $store,
        ProbeClient $probe,
        string $cacheType = CacheTypes::PING,
        ?EventManager $events = null,
    ): AppMonitor {
        $apiClient = $this->createStub(ApiClient::class);
        $apiClient->method('getApiBase')->willReturn('https://node.example.test/api');

        return new AppMonitor(
            $store,
            new ApiCacheKeyFactory(),
            new CacheTtlResolver([$cacheType => $cacheType === CacheTypes::NONE ? 0 : 60]),
            $apiClient,
            $probe,
            $events ?? $this->createStub(EventManager::class),
            new NullLogger(),
        );
    }

    private function probe(Response ...$responses): ProbeClient
    {
        return new ProbeClient(true, new Client([
            'handler' => HandlerStack::create(new MockHandler($responses)),
        ]));
    }
}
