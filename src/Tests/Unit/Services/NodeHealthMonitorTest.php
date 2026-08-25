<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Mata\Config\Monitoring\Section\TtlConfig;
use Mata\Services\Cache\ApiCacheKeyFactory;
use Mata\Services\Cache\CacheEntry;
use Mata\Services\Cache\CacheStoreInterface;
use Mata\Services\Cache\CacheTtlResolver;
use Mata\Services\Cache\CacheType;
use Mata\Services\Collect\NodeApiClient;
use Mata\Services\Event\Dto\EventCommand;
use Mata\Services\Event\EventManager;
use Mata\Services\Monitor\Dto\NodeHealthResult;
use Mata\Services\Monitor\Dto\NodeHealthState;
use Mata\Services\Monitor\Dto\ProbePolicy;
use Mata\Services\Monitor\HttpProbeClient;
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
    public function testDerivesState(array $health, NodeHealthState $state): void
    {
        $store = $this->createMock(CacheStoreInterface::class);
        $store->method('get')->willReturn(null);
        $store->expects($this->once())->method('put')->willReturn(true);
        $monitor = $this->monitor($store, $this->probe($health));

        $this->assertSame($state, $monitor->checkSynchronously('node-a')->state);
    }

    /** @return iterable<string,array{array<string,mixed>,NodeHealthState}> */
    public static function healthResults(): iterable
    {
        yield 'healthy response' => [['status' => 'healthy', 'maintenance_mode' => false], NodeHealthState::Healthy];

        yield 'maintenance response' => [['status' => 'healthy', 'maintenance_mode' => true], NodeHealthState::Maintenance];

        yield 'unhealthy 503 response' => [['status' => 'unhealthy', 'httpCode' => 503, 'maintenance_mode' => false], NodeHealthState::Unhealthy];
    }

    public function testUsesCachedHealthResult(): void
    {
        $store = $this->createMock(CacheStoreInterface::class);
        $store->expects($this->once())->method('get')->willReturn(new CacheEntry([
            'status' => 'healthy', 'maintenance_mode' => false, 'state' => NodeHealthState::Healthy->value,
        ], time()));
        $store->expects($this->never())->method('put');

        $this->assertSame(NodeHealthState::Healthy, $this->monitor($store, new HttpProbeClient())->checkSynchronously('node-a')->state);
    }

    public function testForcedRefreshBypassesCachedHealth(): void
    {
        $store = $this->createMock(CacheStoreInterface::class);
        $store->expects($this->never())->method('get');
        $store->expects($this->once())->method('put')->willReturn(true);
        $monitor = $this->monitor($store, $this->probe(['status' => 'healthy', 'maintenance_mode' => false]));

        $health = $monitor->checkSynchronously('node-a', forceRefresh: true);

        $this->assertSame(NodeHealthState::Healthy, $health->state);
    }

    public function testAsyncCompletionCachesAndEmitsUnhealthyEvent(): void
    {
        $store = $this->createMock(CacheStoreInterface::class);
        $store->method('get')->willReturn(null);
        $store->expects($this->once())->method('put')->willReturn(true);
        $events = $this->createMock(EventManager::class);
        $events->expects($this->once())
            ->method('handle')
            ->with($this->callback(static fn (EventCommand $event): bool => $event->type === 'node_unhealthy'
                && $event->message !== ''
                && $event->level === 'warning'
                && $event->server === 'node-a'
                && $event->context === []
                && $event->metric?->name === 'node_health'
                && $event->metric->value === 1
                && $event->metric->additional['http_code'] === 200
                && $event->metric->additional['attempts'] === 1
                && $event->metric->additional['timeout_ms'] === 5000))
            ->willReturn([]);
        $monitor = $this->monitor($store, $this->probe(['status' => 'unhealthy', 'maintenance_mode' => false]), $events);

        $pending = $monitor->checkAsync('node-a');
        $this->assertInstanceOf(PromiseInterface::class, $pending);
        $result = $pending->wait();
        $this->assertInstanceOf(NodeHealthResult::class, $result);
        $this->assertSame(NodeHealthState::Unhealthy, $result->state);
    }

    public function testTransientFailureRetriesAndSuccessfulRetryDoesNotEmitAnEvent(): void
    {
        $store = $this->createStub(CacheStoreInterface::class);
        $store->method('get')->willReturn(null);
        $events = $this->createMock(EventManager::class);
        $events->expects($this->never())->method('handle');
        $failure = new ConnectException('Operation timed out', new Request('GET', 'https://node.example.test/api/health'), null, ['errno' => 28]);

        $result = $this->monitor($store, $this->probe($failure, ['status' => 'healthy']), $events, static function (int $micros): void {})
            ->checkSynchronously('node-a');

        $this->assertSame(NodeHealthState::Healthy, $result->status);
        $this->assertSame(2, $result->attempts);
    }

    public function testTwoTransientFailuresEmitOneNodeEventWithDiagnostics(): void
    {
        $store = $this->createStub(CacheStoreInterface::class);
        $store->method('get')->willReturn(null);
        $event = null;
        $events = $this->createMock(EventManager::class);
        $events->expects($this->once())->method('handle')->willReturnCallback(static function (EventCommand $command) use (&$event): array {
            $event = $command;

            return [];
        });
        $failure = new ConnectException('Operation timed out', new Request('GET', 'https://node.example.test/api/health'), null, ['errno' => 28]);

        $this->monitor($store, $this->probe($failure, $failure), $events, static function (int $micros): void {})->checkSynchronously('node-a');

        $this->assertSame('node_unreachable', $event?->type);
        $this->assertSame(2, $event?->metric?->additional['attempts']);
        $this->assertSame('timeout', $event?->metric?->additional['failure_kind']);
    }

    public function testHonorsConfiguredRetryBudgetAcrossMultipleTransientFailures(): void
    {
        $store = $this->createStub(CacheStoreInterface::class);
        $store->method('get')->willReturn(null);
        $events = $this->createStub(EventManager::class);
        $failure = static fn (): ConnectException => new ConnectException(
            'Operation timed out',
            new Request('GET', 'https://node.example.test/api/health'),
            null,
            ['errno' => 28],
        );
        $retryDelays = [];
        $result = $this->monitor(
            $store,
            $this->probe($failure(), $failure(), ['status' => 'healthy']),
            $events,
            function (int $micros) use (&$retryDelays): void {
                $retryDelays[] = $micros;
            },
            new ProbePolicy(
                timeoutSeconds: 5,
                maxRetries: 2,
                backoffBaseMs: 500,
                backoffMaxMs: 1500,
            ),
        )->checkSynchronously('node-a');

        $this->assertSame(NodeHealthState::Healthy, $result->status);
        $this->assertSame(3, $result->attempts);
        $this->assertCount(2, $retryDelays);
        foreach ($retryDelays as $micros) {
            $this->assertGreaterThanOrEqual(0, $micros);
            $this->assertLessThanOrEqual(1_500_000, $micros);
        }
    }

    private function monitor(CacheStoreInterface $store, HttpProbeClient $probe, ?EventManager $events = null, ?Closure $retryDelay = null, ?ProbePolicy $probePolicy = null): NodeHealthMonitor
    {
        $apiClient = $this->createStub(NodeApiClient::class);
        $apiClient->method('getApiBase')->willReturn('https://node.example.test/api');

        return new NodeHealthMonitor(
            $store,
            new ApiCacheKeyFactory(),
            new CacheTtlResolver(new TtlConfig([CacheType::Health->value => 60])),
            $apiClient,
            $probe,
            $events ?? $this->createStub(EventManager::class),
            new NullLogger(),
            $probePolicy,
            $retryDelay,
        );
    }

    private function probe(mixed ...$results): HttpProbeClient
    {
        $responses = array_map(static fn (mixed $result): mixed => is_array($result)
            ? new Response($result['httpCode'] ?? 200, [], json_encode($result, JSON_THROW_ON_ERROR))
            : $result, $results);

        return new HttpProbeClient(true, new Client([
            'handler' => HandlerStack::create(new MockHandler($responses)),
        ]));
    }
}
