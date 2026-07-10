<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use Mata\Services\Cache\ApiCacheKeyFactory;
use Mata\Services\Cache\FileCacheStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * @covers \Mata\Services\Cache\FileCacheStore
 *
 * @internal
 */
final class FileCacheStoreTest extends TestCase
{
    private string $directory;
    private ApiCacheKeyFactory $keys;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/mata-file-cache-' . bin2hex(random_bytes(8));
        $this->keys = new ApiCacheKeyFactory();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->directory);
    }

    public function testDisabledStoreAndTtlZeroReadsDoNotCreateCacheDirectories(): void
    {
        $key = $this->keys->createApiRequestKey('https://api.example.test', 'GET', 'apps');
        $disabled = new FileCacheStore(new NullLogger());
        $store = new FileCacheStore(new NullLogger(), $this->directory);

        $this->assertNull($disabled->get($key, 60));
        $this->assertTrue($disabled->put($key, ['apps' => []]));
        $this->assertNull($store->get($key, 0));
        $this->assertNull($store->getStale($key, 0));
        $this->assertDirectoryDoesNotExist($this->directory);
    }

    public function testColdReadMissDoesNotLogOrCreateDirectories(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('warning');
        $store = new FileCacheStore($logger, $this->directory);
        $key = $this->keys->createApiRequestKey('https://api.example.test', 'GET', 'apps');

        $this->assertNull($store->get($key, 60));
        $this->assertDirectoryDoesNotExist($this->directory);
    }

    public function testFreshStalePermanentAndFutureEntriesFollowTtlContract(): void
    {
        $store = new FileCacheStore(new NullLogger(), $this->directory);
        $key = $this->keys->createApiRequestKey('https://api.example.test', 'GET', 'apps');

        $this->assertTrue($store->put($key, ['value' => 'cached']));
        $path = $this->directory . '/api_example_test/GET/apps.json';
        touch($path, time() - 60);
        clearstatcache(true, $path);

        $this->assertNull($store->get($key, 60));
        $this->assertSame(['value' => 'cached'], $store->getStale($key, 60)?->value);
        $this->assertSame(['value' => 'cached'], $store->get($key, -1)?->value);

        touch($path, time() + 60);
        clearstatcache(true, $path);
        $entry = $store->get($key, 1);
        $this->assertNotNull($entry);
        $this->assertGreaterThan(time(), $entry->storedAt);
    }

    public function testWrittenCacheFileIsGroupReadable(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('POSIX file permissions are unavailable on Windows.');
        }

        $store = new FileCacheStore(new NullLogger(), $this->directory);
        $key = $this->keys->createApiRequestKey('https://api.example.test', 'GET', 'apps');
        $path = $this->directory . '/api_example_test/GET/apps.json';

        $this->assertTrue($store->put($key, ['value' => 'cached']));
        clearstatcache(true, $path);
        $permissions = fileperms($path);

        $this->assertIsInt($permissions);
        $this->assertSame(0o060, $permissions & 0o060);
    }

    public function testRoundTripUsesFileMtimeAndRejectsMalformedPayloads(): void
    {
        $store = new FileCacheStore(new NullLogger(), $this->directory);
        $key = $this->keys->createApiRequestKey('https://api.example.test', 'GET', 'nested/data');

        $this->assertTrue($store->put($key, ['one' => ['two' => 2]]));
        $path = $this->directory . '/api_example_test/GET/nested/data.json';
        $storedAt = filemtime($path);
        $entry = $store->get($key, 60);
        $this->assertNotNull($entry);
        $this->assertSame(['one' => ['two' => 2]], $entry->value);
        $this->assertSame($storedAt, $entry->storedAt);

        file_put_contents($path, '"scalar"');
        $this->assertNull($store->getStale($key, 60));
        file_put_contents($path, '{broken');
        $this->assertNull($store->getStale($key, 60));
    }

    public function testEncodingFailureDoesNotCreateAFileOrDirectory(): void
    {
        $store = new FileCacheStore(new NullLogger(), $this->directory);
        $key = $this->keys->createApiRequestKey('https://api.example.test', 'GET', 'recursive');
        $recursive = [];
        $recursive['self'] = &$recursive;

        $this->assertFalse($store->put($key, $recursive));
        $this->assertDirectoryDoesNotExist($this->directory);
    }

    public function testDirectoryCreationFailureDoesNotLeaveAFinalFile(): void
    {
        $store = new FileCacheStore(new NullLogger(), '/dev/null/mata-cache');
        $key = $this->keys->createApiRequestKey('https://api.example.test', 'GET', 'apps');

        $this->assertFalse($store->put($key, ['apps' => []]));
        $this->assertFileDoesNotExist('/dev/null/mata-cache/api_example_test/GET/apps.json');
    }

    public function testKeyLayoutIsNestedAndAlwaysInsideTheCacheDirectory(): void
    {
        $store = new FileCacheStore(new NullLogger(), $this->directory);
        $key = $this->keys->createApiRequestKey('not a URL', 'get/../../', '/a//../b');

        $this->assertTrue($store->put($key, ['safe' => true]));
        $files = glob($this->directory . '/**', GLOB_BRACE) ?: [];
        $this->assertFileExists($this->directory . '/unknown_host/GET_______/a/unknown_segment/__/b.json');
        $this->assertNotEmpty($files);
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
