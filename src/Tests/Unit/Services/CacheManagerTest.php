<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Watcher\Services\Foundation\CacheManager;
use Watcher\Tests\TestCase;

/**
 * Test suite for CacheManager service class.
 *
 * @covers \Watcher\Services\Foundation\CacheManager
 *
 * @internal
 */
class CacheManagerTest extends TestCase
{
    private CacheManager $cacheManager;
    private CacheManager $disabledCacheManager;
    private LoggerInterface $logger;
    private string $testCacheDir;
    private array $tempPaths = [];
    private array $defaultTtlConfig = [
        'ping' => 360,
        'apps' => 60,
        'serverData' => 10,
        'databases' => 10,
        'appLogs' => 30,
        'serverProcesses' => 30,
        'readme' => 300,
        'errorlog' => 60,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->logger = new NullLogger();
        $this->testCacheDir = $this->createTempDirectory();
        $this->tempPaths[] = $this->testCacheDir;

        $this->cacheManager = new CacheManager($this->logger, $this->testCacheDir, $this->defaultTtlConfig);
        $this->disabledCacheManager = new CacheManager($this->logger, null, $this->defaultTtlConfig);
    }

    protected function tearDown(): void
    {
        $this->cleanupTempFiles($this->tempPaths);
        parent::tearDown();
    }

    /**
     * @test
     *
     * @group cache-manager
     * @group initialization
     */
    public function testItInitializesWithEnabledCaching(): void
    {
        $this->assertInstanceOf(CacheManager::class, $this->cacheManager);
        $this->assertTrue($this->cacheManager->isCachingEnabled());
        $this->assertEquals($this->testCacheDir, $this->cacheManager->getCacheDir());
    }

    /**
     * @test
     *
     * @group cache-manager
     * @group initialization
     */
    public function testItInitializesWithDisabledCaching(): void
    {
        $this->assertInstanceOf(CacheManager::class, $this->disabledCacheManager);
        $this->assertFalse($this->disabledCacheManager->isCachingEnabled());
        $this->assertNull($this->disabledCacheManager->getCacheDir());
    }

    /**
     * @test
     *
     * @group cache-manager
     * @group initialization
     */
    public function testItInitializesWithTtlConfiguration(): void
    {
        $this->assertEquals(60, $this->cacheManager->getTtl('apps'));
        $this->assertEquals(10, $this->cacheManager->getTtl('serverData'));
        $this->assertEquals(0, $this->cacheManager->getTtl('nonexistent'));
        $this->assertEquals($this->defaultTtlConfig, $this->cacheManager->getAllTtl());
    }

    /**
     * @test
     *
     * @group cache-manager
     * @group path-generation
     */
    public function testItGeneratesCachePathForValidUrl(): void
    {
        $apiBase = 'https://api.example.com:8080';
        $method = 'GET';
        $path = '/api/v1/data';

        $cachePath = $this->cacheManager->generateCachePath($apiBase, $method, $path);

        $this->assertNotNull($cachePath);
        $this->assertStringContainsString($this->testCacheDir, $cachePath);
        $this->assertStringContainsString('api_example_com', $cachePath);
        $this->assertStringContainsString('GET', $cachePath);
        $this->assertStringEndsWith('data.json', $cachePath);
        $this->assertTrue(is_dir(dirname($cachePath)));
    }

    /**
     * @test
     *
     * @group cache-manager
     * @group path-generation
     */
    public function testItReturnsNullCachePathWhenCachingDisabled(): void
    {
        $cachePath = $this->disabledCacheManager->generateCachePath('https://api.example.com', 'GET', '/data');
        $this->assertNull($cachePath);
    }

    /**
     * @test
     *
     * @group cache-manager
     * @group path-generation
     */
    #[DataProvider('invalidUrlProvider')]
    public function testItHandlesInvalidUrlsGracefully(?string $apiBase, string $expectedHost): void
    {
        $cachePath = $this->cacheManager->generateCachePath($apiBase ?? '', 'GET', '/test');

        if ($cachePath) {
            $this->assertStringContainsString($expectedHost, $cachePath);
        }
    }

    public static function invalidUrlProvider(): array
    {
        return [
            'null host' => [null, 'unknown_host'],
            'empty string' => ['', 'unknown_host'],
            'invalid url' => ['not-a-valid-url', 'unknown_host'],
            'no protocol' => ['example.com', 'example_com'],
        ];
    }

    /**
     * @test
     *
     * @group cache-manager
     * @group path-generation
     */
    public function testItSanitizesSpecialCharactersInPaths(): void
    {
        $apiBase = 'https://api.test.com';
        $method = 'POST';
        $path = '/api/v1/special@#$%^&*()path';

        $cachePath = $this->cacheManager->generateCachePath($apiBase, $method, $path);

        $this->assertNotNull($cachePath);
        $this->assertStringContainsString('special_________path.json', $cachePath);
    }

    /**
     * @test
     *
     * @group cache-manager
     * @group path-generation
     */
    public function testItHandlesEmptyAndRootPaths(): void
    {
        $apiBase = 'https://api.example.com';

        $cachePath1 = $this->cacheManager->generateCachePath($apiBase, 'GET', '');
        $cachePath2 = $this->cacheManager->generateCachePath($apiBase, 'GET', '/');

        $this->assertNotNull($cachePath1);
        $this->assertNotNull($cachePath2);
        $this->assertStringEndsWith('unknown_path.json', $cachePath1);
        $this->assertStringEndsWith('unknown_path.json', $cachePath2);
    }

    /**
     * @test
     *
     * @group cache-manager
     * @group cache-validation
     */
    #[DataProvider('ttlProvider')]
    public function testItValidatesCacheFreshnessCorrectly(int $ttlSeconds, int $fileAge, bool $expectedFresh): void
    {
        $testFile = $this->createTempFile('test data');
        $this->tempPaths[] = $testFile;

        // Set file modification time
        touch($testFile, time() - $fileAge);

        $isFresh = $this->cacheManager->isFreshCache($testFile, $ttlSeconds);
        $this->assertEquals($expectedFresh, $isFresh);
    }

    public static function ttlProvider(): array
    {
        return [
            'fresh cache' => [60, 30, true],   // TTL 60s, file 30s old = fresh
            'expired cache' => [60, 90, false], // TTL 60s, file 90s old = expired
            'permanent cache' => [-1, 3600, true], // TTL -1 = always fresh
            'zero ttl' => [0, 1, false],       // TTL 0 = always expired
            'just expired' => [60, 60, false], // TTL 60s, file 60s old = expired
            'just fresh' => [60, 59, true],     // TTL 60s, file 59s old = fresh
        ];
    }

    /**
     * @test
     *
     * @group cache-manager
     * @group cache-validation
     */
    public function testItReturnsFalseForNonexistentCacheFile(): void
    {
        $nonexistentFile = '/tmp/nonexistent_cache_file_' . uniqid();
        $isFresh = $this->cacheManager->isFreshCache($nonexistentFile, 60);

        $this->assertFalse($isFresh);
    }

    /**
     * @test
     *
     * @group cache-manager
     * @group cache-operations
     */
    public function testItWritesAndReadsCacheSuccessfully(): void
    {
        $testData = ['key' => 'value', 'number' => 42, 'array' => [1, 2, 3]];
        $cacheFile = $this->createTempFile('');
        $this->tempPaths[] = $cacheFile;

        $writeResult = $this->cacheManager->writeCache($cacheFile, $testData);
        $this->assertTrue($writeResult);

        $cacheData = $this->cacheManager->readCache($cacheFile, 60);

        $this->assertNotNull($cacheData);
        $this->assertEquals($testData, $cacheData);
        $this->assertEquals(60, $this->cacheManager->getCacheTtl());
        $this->assertGreaterThan(0, $this->cacheManager->getCacheAge());
    }

    /**
     * @test
     *
     * @group cache-manager
     * @group cache-operations
     */
    public function testItHandlesReadFailureGracefully(): void
    {
        $nonexistentFile = '/tmp/nonexistent_' . uniqid();
        $cacheData = $this->cacheManager->readCache($nonexistentFile, 60);

        $this->assertNull($cacheData);
    }

    /**
     * @test
     *
     * @group cache-manager
     * @group cache-operations
     */
    public function testItHandlesWriteFailureGracefully(): void
    {
        $invalidPath = '/invalid/path/that/does/not/exist';
        $result = $this->cacheManager->writeCache($invalidPath, ['test' => 'data']);

        $this->assertFalse($result);
    }

    /**
     * @test
     *
     * @group cache-manager
     * @group stale-cache
     */
    public function testItRetrievesStaleCacheAsFallback(): void
    {
        $testData = ['stale' => 'data'];
        $cacheFile = $this->createTempFile(json_encode($testData));
        $this->tempPaths[] = $cacheFile;

        // Make file old
        touch($cacheFile, time() - 3600);

        $staleData = $this->cacheManager->readCache($cacheFile, 60);

        $this->assertNotNull($staleData);
        $this->assertEquals($testData, $staleData);
    }

    /**
     * @test
     *
     * @group cache-manager
     * @group stale-cache
     */
    public function testItReturnsNullForMissingStaleCache(): void
    {
        $staleData = $this->cacheManager->readCache('/nonexistent/file', 60);
        $this->assertNull($staleData);
    }

    /**
     * @test
     *
     * @group cache-manager
     * @group url-key-conversion
     */
    #[DataProvider('urlKeyProvider')]
    public function testItConvertsUrlToKeyAndBack(string $originalUrl): void
    {
        $key = $this->cacheManager->urlToKey($originalUrl);
        $convertedUrl = $this->cacheManager->keyToUrl($key);

        $this->assertEquals($originalUrl, $convertedUrl);

        // Key should be filesystem safe
        $this->assertDoesNotMatchRegularExpression('/[\/\\\:*?"<>|]/', $key);
    }

    public static function urlKeyProvider(): array
    {
        return [
            'simple url' => ['https://api.example.com/data'],
            'complex url' => ['https://user:pass@api.example.com:8080/path/to/resource?param=value&other=test'],
            'special characters' => ['https://api.test.com/path with spaces/special@chars#fragment'],
            'unicode' => ['https://тест.example.com/ページ'],
            'empty string' => [''],
            'just domain' => ['example.com'],
        ];
    }

    /**
     * @test
     *
     * @group cache-manager
     * @group ttl-management
     */
    public function testItManagesTtlConfiguration(): void
    {
        $newTtlConfig = ['apps' => 120, 'serverData' => 20, 'newType' => 300];

        $this->cacheManager->setTtlConfig($newTtlConfig);

        $this->assertEquals(120, $this->cacheManager->getTtl('apps'));
        $this->assertEquals(20, $this->cacheManager->getTtl('serverData'));
        $this->assertEquals(300, $this->cacheManager->getTtl('newType'));
        $this->assertEquals(0, $this->cacheManager->getTtl('nonexistent'));
        $this->assertEquals($newTtlConfig, $this->cacheManager->getAllTtl());
    }

    /**
     * @test
     *
     * @group cache-manager
     * @group ttl-management
     */
    public function testItResetsCacheTtlToForceRefresh(): void
    {
        $this->cacheManager->resetCacheTtl();

        $resetKeys = ['apps', 'serverData', 'ping', 'databases', 'appLogs', 'serverProcesses', 'readme', 'errorlog'];

        foreach ($resetKeys as $key) {
            $this->assertEquals(0, $this->cacheManager->getTtl($key));
        }
    }

    /**
     * @test
     *
     * @group cache-manager
     * @group cache-metadata
     */
    public function testItTracksCacheAgeAndTtl(): void
    {
        $initialAge = $this->cacheManager->getCacheAge();
        $initialTtl = $this->cacheManager->getCacheTtl();

        $this->assertEquals(0, $initialAge);
        $this->assertEquals(0, $initialTtl);

        $this->cacheManager->setCacheTtl(300);
        $this->assertEquals(300, $this->cacheManager->getCacheTtl());

        // Test cache age tracking through file operations
        $testData = ['test' => 'data'];
        $cacheFile = $this->createTempFile('');
        $this->tempPaths[] = $cacheFile;

        $this->cacheManager->writeCache($cacheFile, $testData);
        $this->cacheManager->readCache($cacheFile, 60);

        $cacheAge = $this->cacheManager->getCacheAge();
        $this->assertGreaterThan(0, $cacheAge);
    }

    /**
     * @test
     *
     * @group cache-manager
     * @group host-cache-clearing
     */
    public function testItClearsHostCacheSuccessfully(): void
    {
        $apiBase = 'https://api.example.com';

        // Generate and create some cache files
        $cachePath1 = $this->cacheManager->generateCachePath($apiBase, 'GET', '/data1');
        $cachePath2 = $this->cacheManager->generateCachePath($apiBase, 'POST', '/data2');

        $this->assertNotNull($cachePath1);
        $this->assertNotNull($cachePath2);

        file_put_contents($cachePath1, '{"test": "data1"}');
        file_put_contents($cachePath2, '{"test": "data2"}');

        $this->assertTrue(file_exists($cachePath1));
        $this->assertTrue(file_exists($cachePath2));

        // Clear host cache
        $result = $this->cacheManager->clearHostCache($apiBase);
        $this->assertTrue($result);

        // Files should be gone
        $this->assertFalse(file_exists($cachePath1));
        $this->assertFalse(file_exists($cachePath2));
    }

    /**
     * @test
     *
     * @group cache-manager
     * @group host-cache-clearing
     */
    public function testItHandlesClearHostCacheForDisabledCaching(): void
    {
        $result = $this->disabledCacheManager->clearHostCache('https://api.example.com');
        $this->assertFalse($result);
    }

    /**
     * @test
     *
     * @group cache-manager
     * @group host-cache-clearing
     */
    public function testItHandlesClearHostCacheForInvalidUrls(): void
    {
        $result = $this->cacheManager->clearHostCache('invalid-url');
        $this->assertFalse($result);

        $result = $this->cacheManager->clearHostCache('');
        $this->assertFalse($result);
    }

    /**
     * @test
     *
     * @group cache-manager
     * @group cache-statistics
     */
    public function testItGeneratesCacheStatisticsWhenEnabled(): void
    {
        // Create some cache files
        $subDir = $this->testCacheDir . '/test_host/GET';
        mkdir($subDir, 0777, true);

        file_put_contents($subDir . '/file1.json', '{"size": "small"}');
        file_put_contents($subDir . '/file2.json', '{"size": "medium data"}');
        file_put_contents($subDir . '/file3.txt', 'not json'); // Should be ignored

        $stats = $this->cacheManager->getCacheStats();

        $this->assertArrayStructure([
            'enabled' => 'boolean',
            'file_count' => 'integer',
            'total_size_bytes' => 'integer',
            'total_size_human' => 'string',
            'directory' => 'string',
        ], $stats);

        $this->assertTrue($stats['enabled']);
        $this->assertEquals(2, $stats['file_count']); // Only .json files counted
        $this->assertGreaterThan(0, $stats['total_size_bytes']);
        $this->assertEquals($this->testCacheDir, $stats['directory']);
        $this->assertMatchesRegularExpression('/\d+(\.\d+)?\s[KMGT]?B/', $stats['total_size_human']);
    }

    /**
     * @test
     *
     * @group cache-manager
     * @group cache-statistics
     */
    public function testItGeneratesCacheStatisticsWhenDisabled(): void
    {
        $stats = $this->disabledCacheManager->getCacheStats();

        $this->assertArrayStructure([
            'enabled' => 'boolean',
            'file_count' => 'integer',
            'total_size_bytes' => 'integer',
            'directory' => null,
        ], $stats);

        $this->assertFalse($stats['enabled']);
        $this->assertEquals(0, $stats['file_count']);
        $this->assertEquals(0, $stats['total_size_bytes']);
        $this->assertNull($stats['directory']);
    }

    /**
     * @test
     *
     * @group cache-manager
     * @group cache-statistics
     */
    public function testItHandlesMissingCacheDirectoryInStats(): void
    {
        $cacheManager = new CacheManager($this->logger, '/nonexistent/cache/dir', []);
        $stats = $cacheManager->getCacheStats();

        $this->assertFalse($stats['enabled']);
        $this->assertEquals(0, $stats['file_count']);
        $this->assertEquals(0, $stats['total_size_bytes']);
    }

    /**
     * @test
     *
     * @group cache-manager
     * @group utility-functions
     */
    #[DataProvider('bytesFormatProvider')]
    public function testItFormatsBytesCorrectly(int $bytes, string $expectedPattern): void
    {
        $cacheManager = new CacheManager($this->logger, $this->testCacheDir, []);

        // Create a file with specific size to test byte formatting
        $testFile = $this->testCacheDir . '/test_size.json';
        file_put_contents($testFile, str_repeat('x', $bytes));

        $stats = $cacheManager->getCacheStats();

        $this->assertMatchesRegularExpression($expectedPattern, $stats['total_size_human']);
    }

    public static function bytesFormatProvider(): array
    {
        return [
            'bytes' => [500, '/^500 B$/'],
            'kilobytes' => [1536, '/^1\.5 KB$/'], // 1.5 KB
            'megabytes' => [2097152, '/^2 MB$/'],  // 2 MB
            'zero bytes' => [0, '/^0 B$/'],
        ];
    }

    /**
     * @test
     *
     * @group cache-manager
     * @group edge-cases
     */
    public function testItHandlesCorruptedCacheFiles(): void
    {
        $corruptedFile = $this->createTempFile('invalid json content {[');
        $this->tempPaths[] = $corruptedFile;

        $cacheData = $this->cacheManager->readCache($corruptedFile, 60);

        $this->assertNull($cacheData); // json_decode should return null for invalid JSON
    }

    /**
     * @test
     *
     * @group cache-manager
     * @group edge-cases
     */
    public function testItHandlesEmptyCacheFiles(): void
    {
        $emptyFile = $this->createTempFile('');
        $this->tempPaths[] = $emptyFile;

        $cacheData = $this->cacheManager->readCache($emptyFile, 60);

        $this->assertNull($cacheData); // json_decode of empty string returns null
    }

    /**
     * @test
     *
     * @group cache-manager
     * @group edge-cases
     */
    public function testItHandlesVeryLongPaths(): void
    {
        $longPath = '/' . str_repeat('very-long-path-segment/', 20) . 'endpoint';
        $apiBase = 'https://api.example.com';

        $cachePath = $this->cacheManager->generateCachePath($apiBase, 'GET', $longPath);

        $this->assertNotNull($cachePath);
        $this->assertTrue(strlen($cachePath) > 0);
        $this->assertTrue(is_dir(dirname($cachePath)));
    }

    /**
     * @test
     *
     * @group cache-manager
     * @group permissions
     */
    public function testItCreatesCacheDirectoriesWithProperPermissions(): void
    {
        $apiBase = 'https://newhost.example.com';
        $cachePath = $this->cacheManager->generateCachePath($apiBase, 'GET', '/new/deep/path');

        $this->assertNotNull($cachePath);

        $directory = dirname($cachePath);
        $this->assertTrue(is_dir($directory));
        $this->assertTrue(is_writable($directory));
    }
}
