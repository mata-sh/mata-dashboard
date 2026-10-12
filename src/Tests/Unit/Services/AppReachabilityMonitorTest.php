<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Mata\Config\Monitoring\Section\ProbesConfig;
use Mata\Config\Monitoring\Section\TtlConfig;
use Mata\Services\Cache\ApiCacheKeyFactory;
use Mata\Services\Cache\CacheStoreInterface;
use Mata\Services\Cache\CacheTtlResolver;
use Mata\Services\Cache\CacheType;
use Mata\Services\Cache\Dto\CacheEntry;
use Mata\Services\Collect\NodeApiClient;
use Mata\Services\Event\Dto\EventCommand;
use Mata\Services\Event\EventManager;
use Mata\Services\Monitor\AppReachabilityMonitor;
use Mata\Services\Monitor\Dto\AppReachabilityPolicy;
use Mata\Services\Monitor\Dto\EndpointProbeResult;
use Mata\Services\Monitor\Dto\EndpointTarget;
use Mata\Services\Monitor\Dto\ProbePolicy;
use Mata\Services\Monitor\HttpProbeClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \Mata\Services\Monitor\AppReachabilityMonitor
 *
 * @internal
 */
final class AppReachabilityMonitorTest extends TestCase
{
    public function testUsesCachedAvailabilityResult(): void
    {
        $store = $this->createMock(CacheStoreInterface::class);
        $store->expects($this->once())->method('get')->willReturn(new CacheEntry(['reachable' => true], time() - 1));
        $store->expects($this->never())->method('put');

        $result = $this->monitor($store, new HttpProbeClient())->checkEndpoint(
            new EndpointTarget('app', 'https://app.example.test')
        );

        $this->assertInstanceOf(EndpointProbeResult::class, $result);
        $this->assertTrue($result->reachable);
    }

    public function testTtlZeroStillProbesAndWritesThroughToStore(): void
    {
        $store = $this->createMock(CacheStoreInterface::class);
        $store->expects($this->exactly(2))->method('get')->willReturn(null);
        $store->expects($this->exactly(2))->method('put')->with($this->isString(), $this->isArray())->willReturn(true);
        $probe = $this->probe(new Response(503));

        $result = $this->monitor($store, $probe, CacheType::None)->checkEndpoint(
            new EndpointTarget('app', 'https://app.example.test'),
            2,
            CacheType::None
        );

        $this->assertFalse($result->reachable);
        $this->assertSame(503, $result->httpCode);
    }

    public function testTransientFailureThatSucceedsOnRetryDoesNotEmitAnEvent(): void
    {
        $store = $this->createStub(CacheStoreInterface::class);
        $store->method('get')->willReturn(null);
        $events = $this->createMock(EventManager::class);
        $events->expects($this->never())->method('handle');
        $probe = $this->probe(
            new ConnectException('Operation timed out', new Request('HEAD', 'https://app.example.test'), null, ['errno' => 28]),
            new Response(200),
        );

        $result = $this->monitor($store, $probe, CacheType::Ping, $events)->checkMultipleEndpoints([
            'app' => new EndpointTarget('app', 'https://app.example.test'),
        ]);

        $this->assertTrue($result['app']->reachable);
        $this->assertSame(2, $result['app']->attempts);
    }

    public function testFreshCyclesMaintainAndResetPerApplicationStreaks(): void
    {
        $values = [];
        $store = new class ($values) implements CacheStoreInterface {
            /** @var array<string,array<string,mixed>> */
            public array $values;

            public function __construct(array $values)
            {
                $this->values = $values;
            }

            public function get(string $key, int $ttl): ?CacheEntry
            {
                return isset($this->values[$key]) ? new CacheEntry($this->values[$key], time()) : null;
            }

            public function getStale(string $key, int $ttl): ?CacheEntry
            {
                return $this->get($key, $ttl);
            }

            public function put(string $key, array $value): bool
            {
                $this->values[$key] = $value;

                return true;
            }

            public function delete(string $key): bool
            {
                unset($this->values[$key]);

                return true;
            }
        };
        $events = [];
        $manager = $this->createMock(EventManager::class);
        $manager->expects($this->exactly(3))->method('handle')->willReturnCallback(static function (EventCommand $event) use (&$events): array {
            $events[] = $event;

            return [];
        });
        $target = new EndpointTarget('shop', 'https://shop.example.test', serverName: 'node-a', applicationCode: 'shop');
        $monitor = $this->monitor($store, $this->probe(new Response(503), new Response(503), new Response(200), new Response(503)), CacheType::None, $manager);

        $monitor->checkMultipleEndpoints(['shop' => $target], forceRefresh: true);
        $monitor->checkMultipleEndpoints(['shop' => $target], forceRefresh: true);
        $monitor->checkMultipleEndpoints(['shop' => $target], forceRefresh: true);
        $monitor->checkMultipleEndpoints(['shop' => $target], forceRefresh: true);

        $this->assertSame([1, 2, 1], array_map(static fn (EventCommand $event): int => $event->metric->additional['consecutive_failures'], $events));
        $this->assertSame('shop', $events[1]->app);
    }

    public function testAllApplicationTransportFailuresRecordOneServerEvent(): void
    {
        $store = $this->createStub(CacheStoreInterface::class);
        $store->method('get')->willReturn(null);
        $event = null;
        $events = $this->createMock(EventManager::class);
        $events->expects($this->once())->method('handle')->willReturnCallback(static function (EventCommand $command) use (&$event): array {
            $event = $command;

            return [];
        });
        $failure = static fn (): ConnectException => new ConnectException(
            'Operation timed out',
            new Request('HEAD', 'https://app.example.test'),
            null,
            ['errno' => 28],
        );
        $monitor = $this->monitor(
            $store,
            $this->probe($failure(), $failure(), $failure(), $failure()),
            CacheType::Ping,
            $events,
            retryDelay: static function (): void {},
        );

        $monitor->checkMultipleEndpoints([
            'admin' => new EndpointTarget('admin', 'https://admin.example.test', serverName: 'node-a'),
            'shop' => new EndpointTarget('shop', 'https://shop.example.test', serverName: 'node-a'),
        ]);

        $this->assertSame('server_apps_transport_unreachable', $event?->type);
        $this->assertSame('node-a', $event?->server);
        $this->assertNull($event?->app);
        $this->assertSame(['admin', 'shop'], $event?->metric?->additional['affected_apps']);
        $this->assertSame(['timeout'], $event?->metric?->additional['failure_kinds']);
        $this->assertSame(2, $event?->metric?->additional['attempts']);
    }

    public function testTwoTransientFailuresExposeNormalizedDiagnostics(): void
    {
        $store = $this->createStub(CacheStoreInterface::class);
        $store->method('get')->willReturn(null);
        $event = null;
        $events = $this->createMock(EventManager::class);
        $events->expects($this->once())->method('handle')->willReturnCallback(static function (EventCommand $command) use (&$event): array {
            $event = $command;

            return [];
        });
        $failure = new ConnectException('Operation timed out with 0 bytes received', new Request('HEAD', 'https://app.example.test'), null, ['errno' => 28]);
        $monitor = $this->monitor($store, $this->probe($failure, $failure), CacheType::Ping, $events, retryDelay: static function (): void {});

        $monitor->checkMultipleEndpoints(['app' => new EndpointTarget('app', 'https://app.example.test')]);

        $this->assertSame(2, $event?->metric?->additional['attempts']);
        $this->assertSame('timeout', $event?->metric?->additional['failure_kind']);
        $this->assertSame(28, $event?->metric?->additional['curl_errno']);
    }

    public function testDeterministicHttpFailuresAreNotRetried(): void
    {
        $store = $this->createStub(CacheStoreInterface::class);
        $store->method('get')->willReturn(null);
        $events = $this->createStub(EventManager::class);
        $result = $this->monitor($store, $this->probe(new Response(503), new Response(200)), CacheType::Ping, $events, retryDelay: static function (): void {})
            ->checkMultipleEndpoints(['app' => new EndpointTarget('app', 'https://app.example.test')]);

        $this->assertSame(1, $result['app']->attempts);
        $this->assertSame(503, $result['app']->httpCode);
    }

    public function testHonorsConfiguredRetryBudgetAndDelaysOncePerRetry(): void
    {
        $store = $this->createStub(CacheStoreInterface::class);
        $store->method('get')->willReturn(null);
        $events = $this->createStub(EventManager::class);
        $failure = static fn (): ConnectException => new ConnectException(
            'Operation timed out',
            new Request('HEAD', 'https://app.example.test'),
            null,
            ['errno' => 28],
        );
        $delays = 0;
        $monitor = $this->monitor(
            $store,
            $this->probe($failure(), $failure(), $failure()),
            CacheType::Ping,
            $events,
            retryDelay: function () use (&$delays): void {
                ++$delays;
            },
            policy: new AppReachabilityPolicy(new ProbePolicy(5, 2, 500, 1500), 600),
        );

        $result = $monitor->checkMultipleEndpoints(['app' => new EndpointTarget('app', 'https://app.example.test')]);

        $this->assertFalse($result['app']->reachable);
        $this->assertSame(3, $result['app']->attempts);
        $this->assertSame(2, $delays);
    }

    public function testZeroRetryBudgetProbesExactlyOnce(): void
    {
        $store = $this->createStub(CacheStoreInterface::class);
        $store->method('get')->willReturn(null);
        $events = $this->createStub(EventManager::class);
        $failure = new ConnectException(
            'Operation timed out',
            new Request('HEAD', 'https://app.example.test'),
            null,
            ['errno' => 28],
        );
        $delays = 0;
        $monitor = $this->monitor(
            $store,
            $this->probe($failure, new Response(200)),
            CacheType::Ping,
            $events,
            retryDelay: function () use (&$delays): void {
                ++$delays;
            },
            policy: new AppReachabilityPolicy(new ProbePolicy(5, 0, 500, 1500), 600),
        );

        $result = $monitor->checkMultipleEndpoints(['app' => new EndpointTarget('app', 'https://app.example.test')]);

        $this->assertFalse($result['app']->reachable);
        $this->assertSame(1, $result['app']->attempts);
        $this->assertSame(0, $delays);
    }

    public function testForcedBatchBypassesCacheAndWritesTheNormalPingKey(): void
    {
        $target = new EndpointTarget('app', 'https://app.example.test');
        $cacheKey = (new ApiCacheKeyFactory())->createAvailabilityKey(
            'https://node.example.test/api',
            CacheType::Ping,
            $target->resolveProbeUrl(),
            5,
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

        $this->assertTrue($results['app']->reachable);
    }

    public function testUnreachableAppEventUsesTheApplicationCodeFromTheAppsPayload(): void
    {
        $store = $this->createMock(CacheStoreInterface::class);
        $store->method('get')->willReturn(null);
        $store->expects($this->exactly(2))->method('put');
        $events = $this->createMock(EventManager::class);
        $recordedEvent = null;
        $events->expects($this->once())
            ->method('handle')
            ->willReturnCallback(static function (EventCommand $event) use (&$recordedEvent): array {
                $recordedEvent = $event;

                return ['eventRecorded' => true, 'eventId' => 1];
            });
        $targets = EndpointTarget::fromRawAppData([
            'shop' => ['url' => 'https://shop.example.test'],
        ], 'node-a');

        $this->monitor($store, $this->probe(new Response(503)), CacheType::Ping, $events)
            ->checkMultipleEndpoints($targets);

        $this->assertInstanceOf(EventCommand::class, $recordedEvent);
        $this->assertSame('node-a', $recordedEvent->server);
        $this->assertSame('shop', $recordedEvent->app);
    }

    public function testEventsDisabledAppProducesNoUnreachableEvent(): void
    {
        $store = $this->createStub(CacheStoreInterface::class);
        $store->method('get')->willReturn(null);
        $events = $this->createMock(EventManager::class);
        $events->expects($this->never())->method('handle');
        $targets = EndpointTarget::fromRawAppData([
            'shop' => ['url' => 'https://shop.example.test', 'monitoring' => ['events' => false]],
        ], 'node-a');

        $result = $this->monitor($store, $this->probe(new Response(503)), CacheType::Ping, $events)
            ->checkMultipleEndpoints($targets);

        $this->assertFalse($result['shop']->reachable);
    }

    public function testAlarmsDisabledStampsAlarmsSuppressedOnTheUnreachableEvent(): void
    {
        $store = $this->createStub(CacheStoreInterface::class);
        $store->method('get')->willReturn(null);
        $recorded = null;
        $events = $this->createMock(EventManager::class);
        $events->expects($this->once())->method('handle')
            ->willReturnCallback(static function (EventCommand $event) use (&$recorded): array {
                $recorded = $event;

                return [];
            });
        $targets = EndpointTarget::fromRawAppData([
            'shop' => ['url' => 'https://shop.example.test', 'monitoring' => ['alarms' => false]],
        ], 'node-a');

        $this->monitor($store, $this->probe(new Response(503)), CacheType::Ping, $events)
            ->checkMultipleEndpoints($targets);

        $this->assertSame('app_unreachable', $recorded?->type);
        $this->assertSame([
            'identifier' => 'shop',
            'url' => 'https://shop.example.test',
            'alarms_suppressed' => true,
        ], $recorded?->context);
    }

    public function testEventsDisabledAppIsExcludedFromTheAllAppsTransportQuorum(): void
    {
        $store = $this->createStub(CacheStoreInterface::class);
        $store->method('get')->willReturn(null);
        $recorded = [];
        $events = $this->createMock(EventManager::class);
        $events->expects($this->once())->method('handle')
            ->willReturnCallback(static function (EventCommand $event) use (&$recorded): array {
                $recorded[] = $event;

                return [];
            });
        $failure = static fn (): ConnectException => new ConnectException(
            'Operation timed out',
            new Request('HEAD', 'https://app.example.test'),
            null,
            ['errno' => 28],
        );
        $targets = EndpointTarget::fromRawAppData([
            'admin' => ['url' => 'https://admin.example.test', 'monitoring' => ['events' => false]],
            'shop' => ['url' => 'https://shop.example.test'],
        ], 'node-a');
        $monitor = $this->monitor(
            $store,
            $this->probe($failure(), $failure(), $failure(), $failure()),
            CacheType::Ping,
            $events,
            retryDelay: static function (): void {},
        );

        $monitor->checkMultipleEndpoints($targets);

        $this->assertCount(1, $recorded);
        $this->assertSame('app_unreachable', $recorded[0]->type);
        $this->assertSame('shop', $recorded[0]->app);
    }

    public function testReachabilityDisabledAppNeitherProbesNorBlocksTheTransportQuorum(): void
    {
        $store = $this->createStub(CacheStoreInterface::class);
        $store->method('get')->willReturn(null);
        $recorded = null;
        $events = $this->createMock(EventManager::class);
        $events->expects($this->once())->method('handle')
            ->willReturnCallback(static function (EventCommand $event) use (&$recorded): array {
                $recorded = $event;

                return [];
            });
        $failure = static fn (): ConnectException => new ConnectException(
            'Operation timed out',
            new Request('HEAD', 'https://app.example.test'),
            null,
            ['errno' => 28],
        );
        $targets = EndpointTarget::fromRawAppData([
            'admin' => ['url' => 'https://admin.example.test'],
            'archived' => ['url' => 'https://archived.example.test', 'monitoring' => ['reachability' => false]],
            'shop' => ['url' => 'https://shop.example.test'],
        ], 'node-a');
        $monitor = $this->monitor(
            $store,
            $this->probe($failure(), $failure(), $failure(), $failure()),
            CacheType::Ping,
            $events,
            retryDelay: static function (): void {},
        );

        $results = $monitor->checkMultipleEndpoints($targets);

        $this->assertSame(['admin', 'shop'], array_keys($results));
        $this->assertSame('server_apps_transport_unreachable', $recorded?->type);
        $this->assertSame(['admin', 'shop'], $recorded?->metric?->additional['affected_apps']);
    }

    public function testStatusEndpointMaintenanceDoesNotRecordUnreachableEvent(): void
    {
        $store = $this->createMock(CacheStoreInterface::class);
        $store->method('get')->willReturn(null);
        $store->expects($this->once())->method('put');
        $events = $this->createMock(EventManager::class);
        $events->expects($this->never())->method('handle');
        $monitor = $this->monitor($store, $this->probe(new Response(503, [], '{"maintenance_mode":true}')), CacheType::Ping, $events);

        $result = $monitor->checkMultipleEndpoints([
            'app' => new EndpointTarget(
                identifier: 'app',
                url: 'https://app.example.test',
                statusUrl: 'https://app.example.test/status.php',
            ),
        ]);

        $this->assertFalse($result['app']->reachable);
        $this->assertTrue($result['app']->maintenanceMode);
        $this->assertSame('https://app.example.test/status.php', $result['app']->url);
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
        $events->expects($this->never())->method('handle');
        $monitor = $this->monitor($store, new HttpProbeClient(), CacheType::Ping, $events);

        $result = $monitor->checkMultipleEndpoints([
            'app' => new EndpointTarget('app', 'https://app.example.test'),
        ]);

        $this->assertTrue($result['app']->maintenanceMode);
    }

    public function testMixedCacheBatchPreservesOrderAndRecordsCachedFailures(): void
    {
        $store = $this->createMock(CacheStoreInterface::class);
        $store->method('get')->willReturnOnConsecutiveCalls(
            new CacheEntry(['reachable' => false, 'httpCode' => null, 'error' => 'cached'], time()),
            null,
            null,
            null,
        );
        $store->expects($this->exactly(3))->method('put');
        $events = $this->createMock(EventManager::class);
        $events->expects($this->once())->method('handle')->willReturn([]);
        $monitor = $this->monitor($store, $this->probe(new Response(200), new Response(503)), CacheType::Ping, $events);

        $results = $monitor->checkMultipleEndpoints([
            'cached' => new EndpointTarget('cached', 'https://cached.example.test'),
            'healthy' => new EndpointTarget('healthy', 'https://healthy.example.test'),
            'down' => new EndpointTarget('down', 'https://down.example.test'),
        ]);

        $this->assertSame(['cached', 'healthy', 'down'], array_keys($results));
        $this->assertFalse($results['cached']->reachable);
        $this->assertTrue($results['healthy']->reachable);
        $this->assertFalse($results['down']->reachable);
    }

    public function testMissingProbeResultIsReportedForItsApplication(): void
    {
        $store = $this->createMock(CacheStoreInterface::class);
        $store->method('get')->willReturn(null);
        $store->expects($this->exactly(2))->method('put');

        $probe = $this->createStub(HttpProbeClient::class);
        $probe->method('probeReachabilityAsync')->willReturn(Create::promiseFor([]));
        $probe->method('settle')->willReturn([]);

        $events = $this->createMock(EventManager::class);
        $events->expects($this->once())->method('handle')->willReturn([]);
        $monitor = $this->monitor($store, $probe, CacheType::Ping, $events);

        $results = $monitor->checkMultipleEndpoints([
            'app' => new EndpointTarget('app', 'https://app.example.test'),
        ]);

        $this->assertFalse($results['app']->reachable);
        $this->assertSame('Availability probe did not return a result', $results['app']->error);
    }

    public function testLimitsUncachedProbesToFourPerBatch(): void
    {
        $store = $this->createMock(CacheStoreInterface::class);
        $store->method('get')->willReturn(null);
        $store->expects($this->exactly(10))->method('put');

        $probe = $this->createMock(HttpProbeClient::class);
        $probe->expects($this->exactly(10))
            ->method('probeReachabilityAsync')
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
        $events->expects($this->never())->method('handle');
        $monitor = $this->monitor($store, $probe, CacheType::Ping, $events);
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
        HttpProbeClient $probe,
        CacheType $cacheType = CacheType::Ping,
        ?EventManager $events = null,
        ?Closure $clock = null,
        ?Closure $retryDelay = null,
        ?AppReachabilityPolicy $policy = null,
    ): AppReachabilityMonitor {
        $apiClient = $this->createStub(NodeApiClient::class);
        $apiClient->method('getApiBase')->willReturn('https://node.example.test/api');

        return new AppReachabilityMonitor(
            $store,
            new ApiCacheKeyFactory(),
            new CacheTtlResolver(new TtlConfig([$cacheType->value => $cacheType === CacheType::None ? 0 : 60])),
            $apiClient,
            $probe,
            $events ?? $this->createStub(EventManager::class),
            new NullLogger(),
            $policy ?? new AppReachabilityPolicy(ProbePolicy::fromConfig(ProbesConfig::default()), 600),
            $clock,
            $retryDelay,
        );
    }

    private function probe(mixed ...$responses): HttpProbeClient
    {
        return new HttpProbeClient(true, new Client([
            'handler' => HandlerStack::create(new MockHandler($responses)),
        ]));
    }
}
