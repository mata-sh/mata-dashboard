<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Mata\Services\Cache\ApiCacheKeyFactory;
use Mata\Services\Cache\CacheEntry;
use Mata\Services\Cache\CacheStoreInterface;
use Mata\Services\Cache\CacheTtlResolver;
use Mata\Services\Cache\FileCacheStore;
use Mata\Services\Collect\ServerDataCollector;
use Mata\Services\DataTransformers\ApiResponseTransformer;
use Mata\Services\Foundation\ApiClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \Mata\Services\Collect\ServerDataCollector
 *
 * @internal
 */
final class ServerDataCollectorTest extends TestCase
{
    public function testServerRoutesUseTheNewNamespace(): void
    {
        $cache = $this->createStub(CacheStoreInterface::class);
        $paths = ['server/data', 'server/info', 'server/php', 'server/databases', 'server/cronjobs'];
        $apiClient = $this->createMock(ApiClient::class);
        $apiClient->method('getApiBase')->willReturn('https://node.example.test/api');
        $apiClient->expects($this->exactly(5))
            ->method('request')
            ->willReturnCallback(function (string $method, string $path) use (&$paths): never {
                $this->assertSame('GET', $method);
                $this->assertSame(array_shift($paths), $path);

                throw new RequestException('Unavailable', new Request($method, $path));
            });

        $collector = new ServerDataCollector(
            new NullLogger(),
            $cache,
            new ApiCacheKeyFactory(),
            new CacheTtlResolver([]),
            $apiClient,
            new ApiResponseTransformer(new NullLogger()),
        );

        $this->assertNull($collector->getServerSystemData());
        $this->assertNull($collector->getServerInfo());
        $this->assertNull($collector->getServerPhpProcesses());
        $this->assertNull($collector->getServerDatabases());
        $this->assertNull($collector->fetchServerCronjobs());
        $this->assertSame([], $paths);
    }

    public function testFreshEntryReportsItsOwnCacheAge(): void
    {
        $cache = $this->createMock(CacheStoreInterface::class);
        $cache->expects($this->once())->method('get')->willReturn(new CacheEntry(['cpu_usage' => 12], time() - 5));
        $apiClient = $this->createMock(ApiClient::class);
        $apiClient->method('getApiBase')->willReturn('https://node.example.test/api');
        $apiClient->expects($this->never())->method('request');

        $collector = new ServerDataCollector(
            new NullLogger(),
            $cache,
            new ApiCacheKeyFactory(),
            new CacheTtlResolver(['serverData' => 60]),
            $apiClient,
            new ApiResponseTransformer(new NullLogger()),
        );

        $response = $collector->getServerSystemData();

        $this->assertTrue($response['from_cache']);
        $this->assertGreaterThanOrEqual(5, $response['cache_age']);
        $this->assertSame(60, $response['cache_ttl']);
    }

    public function testForcedRefreshBypassesCacheAndWritesTheNormalEntry(): void
    {
        $cacheKey = (new ApiCacheKeyFactory())->createApiRequestKey(
            'https://node.example.test/api',
            'GET',
            'apps',
        );
        $cache = $this->createMock(CacheStoreInterface::class);
        $cache->expects($this->never())->method('get');
        $cache->expects($this->once())
            ->method('put')
            ->with($cacheKey, [])
            ->willReturn(true);
        $apiClient = $this->createMock(ApiClient::class);
        $apiClient->method('getApiBase')->willReturn('https://node.example.test/api');
        $apiClient->expects($this->once())->method('request')->willReturn('[]');

        $collector = new ServerDataCollector(
            new NullLogger(),
            $cache,
            new ApiCacheKeyFactory(),
            new CacheTtlResolver(['apps' => 300]),
            $apiClient,
            new ApiResponseTransformer(new NullLogger()),
        );

        $response = $collector->getApps(forceRefresh: true);

        $this->assertFalse($response['from_cache']);
        $this->assertSame([], $response['result']);
    }

    public function testFailedRequestUsesStaleEntryUnlessTtlIsZero(): void
    {
        $cache = $this->createMock(CacheStoreInterface::class);
        $cache->method('get')->willReturn(null);
        $cache->expects($this->once())->method('getStale')->willReturn(new CacheEntry(['apps' => []], time() - 120));
        $apiClient = $this->createStub(ApiClient::class);
        $apiClient->method('getApiBase')->willReturn('https://node.example.test/api');
        $apiClient->method('request')->willThrowException(new RequestException('Unavailable', new Request('GET', 'apps')));

        $collector = new ServerDataCollector(
            new NullLogger(),
            $cache,
            new ApiCacheKeyFactory(),
            new CacheTtlResolver(['apps' => 60]),
            $apiClient,
            new ApiResponseTransformer(new NullLogger()),
        );

        $response = $collector->getApps();

        $this->assertTrue($response['from_cache']);
        $this->assertTrue($response['stale_cache']);
        $this->assertGreaterThanOrEqual(120, $response['cache_age']);
    }

    public function testNoneTtlWritesFreshApiResponseWithoutReadingCache(): void
    {
        $directory = sys_get_temp_dir() . '/mata-collector-cache-' . bin2hex(random_bytes(8));
        $cache = new FileCacheStore(new NullLogger(), $directory);
        $keys = new ApiCacheKeyFactory();
        $key = $keys->createApiRequestKey('https://node.example.test/api', 'GET', 'apps');
        $cache->put($key, ['apps' => ['old']]);
        $path = $directory . '/node_example_test/GET/apps.json';
        touch($path, time() - 60);
        clearstatcache(true, $path);
        $oldStoredAt = filemtime($path);
        $apiClient = $this->createMock(ApiClient::class);
        $apiClient->method('getApiBase')->willReturn('https://node.example.test/api');
        $apiClient->expects($this->once())->method('request')->willReturn('{"apps":["fresh"]}');

        $collector = new ServerDataCollector(
            new NullLogger(),
            $cache,
            $keys,
            new CacheTtlResolver(['none' => 0]),
            $apiClient,
            new ApiResponseTransformer(new NullLogger()),
        );

        $response = $collector->getApps('none');
        $entry = $cache->getStale($key, 60);

        $this->assertFalse($response['from_cache']);
        $this->assertSame(['apps' => ['fresh']], $entry?->value);
        $this->assertGreaterThan($oldStoredAt, $entry?->storedAt);
    }

    public function testNoneTtlDoesNotFallBackToAnExistingStaleEntry(): void
    {
        $directory = sys_get_temp_dir() . '/mata-collector-cache-' . bin2hex(random_bytes(8));
        $cache = new FileCacheStore(new NullLogger(), $directory);
        $key = (new ApiCacheKeyFactory())->createApiRequestKey('https://node.example.test/api', 'GET', 'apps');
        $cache->put($key, ['apps' => ['old']]);
        $apiClient = $this->createStub(ApiClient::class);
        $apiClient->method('getApiBase')->willReturn('https://node.example.test/api');
        $apiClient->method('request')->willThrowException(new RequestException('Unavailable', new Request('GET', 'apps')));

        $collector = new ServerDataCollector(
            new NullLogger(),
            $cache,
            new ApiCacheKeyFactory(),
            new CacheTtlResolver(['none' => 0]),
            $apiClient,
            new ApiResponseTransformer(new NullLogger()),
        );

        $this->assertNull($collector->getApps('none'));
        $this->assertSame(['apps' => ['old']], $cache->getStale($key, 60)?->value);
    }

    public function testTimeoutFallsBackToStaleEntry(): void
    {
        $cache = $this->createMock(CacheStoreInterface::class);
        $cache->method('get')->willReturn(null);
        $cache->expects($this->once())->method('getStale')->willReturn(new CacheEntry(['apps' => []], time() - 120));
        $apiClient = $this->createStub(ApiClient::class);
        $apiClient->method('getApiBase')->willReturn('https://node.example.test/api');
        $apiClient->method('request')->willThrowException(new ConnectException(
            'cURL error 28: Operation timed out after 10001 milliseconds with 0 bytes received',
            new Request('GET', 'apps'),
        ));

        $collector = new ServerDataCollector(
            new NullLogger(),
            $cache,
            new ApiCacheKeyFactory(),
            new CacheTtlResolver(['apps' => 60]),
            $apiClient,
            new ApiResponseTransformer(new NullLogger()),
        );

        $response = $collector->getApps();

        $this->assertTrue($response['from_cache']);
        $this->assertTrue($response['stale_cache']);
    }

    public function testNodeJsonHttpErrorReturnsAnErrorEnvelope(): void
    {
        $cache = $this->createStub(CacheStoreInterface::class);

        $apiClient = $this->createStub(ApiClient::class);
        $apiClient->method('getApiBase')->willReturn('https://node.example.test/api');
        $apiClient->method('request')->willThrowException(new RequestException(
            'Client error',
            new Request('GET', 'apps'),
            new Response(404, [], '{"error":true,"message":"Unknown application."}'),
        ));

        $collector = new ServerDataCollector(
            new NullLogger(),
            $cache,
            new ApiCacheKeyFactory(),
            new CacheTtlResolver(['apps' => 60]),
            $apiClient,
            new ApiResponseTransformer(new NullLogger()),
        );

        $response = $collector->getApps();

        $this->assertNull($response['result']);
        $this->assertSame('node_response', $response['error']['kind']);
        $this->assertSame('Unknown application.', $response['error']['message']);
        $this->assertSame(404, $response['error']['status_code']);
    }
}
