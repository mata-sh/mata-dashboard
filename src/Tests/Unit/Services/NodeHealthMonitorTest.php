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
use Mata\Constants\CacheTypes;
use Mata\Constants\NodeHealthState;
use Mata\Services\Cache\ApiCacheKeyFactory;
use Mata\Services\Cache\CacheEntry;
use Mata\Services\Cache\CacheStoreInterface;
use Mata\Services\Cache\CacheTtlResolver;
use Mata\Services\Event\Dto\EventCommand;
use Mata\Services\Event\EventManager;
use Mata\Services\Foundation\ApiClient;
use Mata\Services\Foundation\ProbeClient;
use Mata\Services\Monitor\Dto\ProbePolicy;
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
        $this->assertSame(NodeHealthState::UNHEALTHY, $pending->wait()['state']);
    }

    public function testTransientFailureRetriesAndSuccessfulRetryDoesNotEmitAnEvent(): void
    {
        $store = $this->createStub(CacheStoreInterface::class);
        $store->method('get')->willReturn(null);
        $events = $this->createMock(EventManager::class);
        $events->expects($this->never())->method('handle');
        $failure = new ConnectException('Operation timed out', new Request('GET', 'https://node.example.test/api/health'), null, ['errno' => 28]);

        $result = $this->monitor($store, $this->probe($failure, ['status' => 'healthy']), $events, static function (): void {})
            ->checkOrWait('node-a');

        $this->assertSame('healthy', $result['status']);
        $this->assertSame(2, $result['attempts']);
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

        $this->monitor($store, $this->probe($failure, $failure), $events, static function (): void {})->checkOrWait('node-a');

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
        $delays = 0;
        $result = $this->monitor(
            $store,
            $this->probe($failure(), $failure(), ['status' => 'healthy']),
            $events,
            function () use (&$delays): void { ++$delays; },
            new ProbePolicy(5, 2, 500, 1500, ['timeout' => true]),
        )->checkOrWait('node-a');

        $this->assertSame('healthy', $result['status']);
        $this->assertSame(3, $result['attempts']);
        $this->assertSame(2, $delays);
    }

    private function monitor(CacheStoreInterface $store, ProbeClient $probe, ?EventManager $events = null, ?Closure $retryDelay = null, ?ProbePolicy $probePolicy = null): NodeHealthMonitor
    {
        $apiClient = $this->createStub(ApiClient::class);
        $apiClient->method('getApiBase')->willReturn('https://node.example.test/api');

        return new NodeHealthMonitor(
            $store,
            new ApiCacheKeyFactory(),
            new CacheTtlResolver([CacheTypes::HEALTH => 60]),
            $apiClient,
            $probe,
            $events ?? $this->createStub(EventManager::class),
            new NullLogger(),
            $probePolicy,
            $retryDelay,
        );
    }

    private function probe(mixed ...$results): ProbeClient
    {
        $responses = array_map(static fn (mixed $result): mixed => is_array($result)
            ? new Response($result['httpCode'] ?? 200, [], json_encode($result, JSON_THROW_ON_ERROR))
            : $result, $results);

        return new ProbeClient(true, new Client([
            'handler' => HandlerStack::create(new MockHandler($responses)),
        ]));
    }
}
