<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use Mata\Services\Cache\ApiCacheKeyFactory;
use Mata\Services\Cache\CacheTtlResolver;
use Mata\Services\Cache\CacheType;
use Mata\Services\Cache\FileCacheStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \Mata\Services\Cache\ApiCacheKeyFactory
 * @covers \Mata\Services\Cache\CacheTtlResolver
 *
 * @internal
 */
final class ApiCacheKeyFactoryTest extends TestCase
{
    public function testApiRequestKeyCarriesTheRequestParts(): void
    {
        $factory = new ApiCacheKeyFactory();
        $key = $factory->createApiRequestKey('https://api.example.test/base', 'get', '/apps/list');

        $this->assertSame([
            'api_base' => 'https://api.example.test/base',
            'method' => 'get',
            'path' => '/apps/list',
        ], json_decode($key, true, 512, JSON_THROW_ON_ERROR));
    }

    public function testOpaqueLeavesAreDeterministicFixedLengthAndNonReversible(): void
    {
        $factory = new ApiCacheKeyFactory();
        $logical = 'https://private.example.test/health?token=secret|timeout_2';
        $leaf = $factory->createOpaqueLeaf($logical);

        $this->assertSame($leaf, $factory->createOpaqueLeaf($logical));
        $this->assertSame(64, strlen($leaf));
        $this->assertStringNotContainsString('private', $leaf);
        $this->assertStringContainsString(
            $factory->createOpaqueLeaf("{$logical}|timeout_2"),
            $factory->createAvailabilityKey('https://api.example.test', CacheType::Ping, $logical, 2)
        );
    }

    public function testInvalidUtf8InputsUseDistinctQuarantinedFallbackKeys(): void
    {
        $factory = new ApiCacheKeyFactory();
        $first = $factory->createApiRequestKey('https://api.example.test', 'GET', "invalid-\xB1");
        $second = $factory->createApiRequestKey('https://api.example.test', 'GET', "invalid-\xB2");
        $decoded = json_decode($first, true, 512, JSON_THROW_ON_ERROR);

        $this->assertNotSame($first, $second);
        $this->assertSame('invalid-key.local', $decoded['api_base']);
        $this->assertSame('GET', $decoded['method']);
        $this->assertMatchesRegularExpression('/^invalid\/[a-f0-9]{64}$/', $decoded['path']);
    }

    public function testInvalidUtf8FallbackKeyRoundTripsThroughFileCacheStore(): void
    {
        $directory = sys_get_temp_dir() . '/mata-invalid-key-' . bin2hex(random_bytes(8));
        $factory = new ApiCacheKeyFactory();
        $key = $factory->createApiRequestKey('https://api.example.test', "GET\xB1", 'apps');
        $store = new FileCacheStore(new NullLogger(), $directory);

        try {
            $this->assertTrue($store->put($key, ['value' => 'cached']));
            $this->assertSame(['value' => 'cached'], $store->get($key, 60)?->value);
        } finally {
            $this->removeDirectory($directory);
        }
    }

    public function testTtlResolverOnlyReturnsConfiguredIntegers(): void
    {
        $resolver = new CacheTtlResolver([
            'apps' => 60,
            'readme' => -1,
            'ping' => '60',
        ]);

        $this->assertSame(60, $resolver->get(CacheType::Apps));
        $this->assertSame(-1, $resolver->get(CacheType::Readme));
        $this->assertSame(0, $resolver->get(CacheType::Ping), 'a non-integer config value means no caching');
        $this->assertSame(0, $resolver->get(CacheType::Audit), 'an unconfigured cache type means no caching');
    }

    /**
     * None means "bypass the cache". The resolver must not consult configuration for it,
     * so that a stray "none" key in ttl.json cannot start caching uncacheable requests.
     */
    public function testTtlResolverIgnoresConfigurationForTheNoneSentinel(): void
    {
        $resolver = new CacheTtlResolver(['none' => 3600]);

        $this->assertSame(0, $resolver->get(CacheType::None));
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . '/' . $item;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($directory);
    }
}
