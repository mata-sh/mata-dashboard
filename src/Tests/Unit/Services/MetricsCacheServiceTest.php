<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use Mata\Services\Cache\ApiCacheKeyFactory;
use Mata\Services\Cache\CacheStoreInterface;
use Mata\Services\Cache\Dto\CacheEntry;
use Mata\Services\Cache\MetricsCacheService;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Mata\Services\Cache\MetricsCacheService
 *
 * @internal
 */
final class MetricsCacheServiceTest extends TestCase
{
    public function testDashboardCacheKeyDistinguishesTimespanAliases(): void
    {
        $service = new MetricsCacheService(
            $this->createStub(CacheStoreInterface::class),
            new ApiCacheKeyFactory(),
            new Logger('test'),
            [],
        );

        $dayKey = $service->buildDashboardCacheKey(['node'], 'cpu', 'day', '24h', 'hourly');
        $recentKey = $service->buildDashboardCacheKey(['node'], 'cpu', 'recent', '24h', 'hourly');

        $this->assertNotSame($dayKey, $recentKey);
    }

    public function testDashboardTtlZeroIsDelegatedToTheStore(): void
    {
        $store = $this->createMock(CacheStoreInterface::class);
        $store->expects($this->once())->method('get')->with($this->isString(), 0)->willReturn(null);
        $service = new MetricsCacheService($store, new ApiCacheKeyFactory(), new Logger('test'), []);

        $this->assertNull($service->readDashboardCache('filters', 0, false));
    }

    public function testPermanentDashboardEntryHasEntrySpecificAgeMetadata(): void
    {
        $store = $this->createStub(CacheStoreInterface::class);
        $store->method('get')->willReturn(new CacheEntry(['series' => []], time() - 20));
        $service = new MetricsCacheService($store, new ApiCacheKeyFactory(), new Logger('test'), []);

        $result = $service->readDashboardCache('filters', -1, false);

        $this->assertNotNull($result);
        $this->assertTrue($result['cache']['from_cache']);
        $this->assertGreaterThanOrEqual(20, $result['cache']['age']);
        $this->assertSame(-1, $result['cache']['ttl']);
    }

    public function testCorruptSeriesEntryIsIgnored(): void
    {
        $store = $this->createStub(CacheStoreInterface::class);
        $store->method('get')->willReturn(new CacheEntry(['filters' => [], 'series' => 'invalid'], time()));
        $service = new MetricsCacheService(
            $store,
            new ApiCacheKeyFactory(),
            new Logger('test'),
            ['node' => ['api_url' => 'https://node.example.test/api']]
        );

        $this->assertNull($service->readSeriesCache('node', 'cpu', 'day', 'hour', -1));
    }
}
