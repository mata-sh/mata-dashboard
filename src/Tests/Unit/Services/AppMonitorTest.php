<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use Mata\Constants\CacheTypes;
use Mata\Services\Cache\ApiCacheKeyFactory;
use Mata\Services\Cache\CacheEntry;
use Mata\Services\Cache\CacheStoreInterface;
use Mata\Services\Cache\CacheTtlResolver;
use Mata\Services\Event\EventManager;
use Mata\Services\Foundation\ApiClient;
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
        $client = $this->createMock(ApiClient::class);
        $client->method('getApiBase')->willReturn('https://node.example.test/api');
        $client->expects($this->never())->method('checkAvailability');

        $monitor = new AppMonitor(
            $store,
            new ApiCacheKeyFactory(),
            new CacheTtlResolver([CacheTypes::PING => 60]),
            $client,
            $this->createMock(EventManager::class),
            new NullLogger(),
        );

        $this->assertSame(['reachable' => true], $monitor->checkEndpoint(new EndpointTarget('app', 'https://app.example.test')));
    }

    public function testTtlZeroStillProbesAndWritesThroughToStore(): void
    {
        $store = $this->createMock(CacheStoreInterface::class);
        $store->expects($this->once())->method('get')->with($this->isType('string'), 0)->willReturn(null);
        $store->expects($this->once())->method('put')->with($this->isType('string'), $this->isType('array'))->willReturn(true);
        $client = $this->createMock(ApiClient::class);
        $client->method('getApiBase')->willReturn('https://node.example.test/api');
        $client->expects($this->once())->method('checkAvailability')->willReturn(['reachable' => false]);

        $monitor = new AppMonitor(
            $store,
            new ApiCacheKeyFactory(),
            new CacheTtlResolver([CacheTypes::NONE => 0]),
            $client,
            $this->createMock(EventManager::class),
            new NullLogger(),
        );

        $this->assertSame(['reachable' => false], $monitor->checkEndpoint(new EndpointTarget('app', 'https://app.example.test'), 2, CacheTypes::NONE));
    }
}
