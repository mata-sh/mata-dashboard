<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Utils;

use InvalidArgumentException;
use Mata\Tests\TestCase;
use Mata\Utils\PathResolver;

/**
 * Test suite for PathResolver utility class.
 *
 * @covers \Mata\Utils\PathResolver
 *
 * @internal
 */
class PathResolverTest extends TestCase
{
    private string $testEntryFile;
    private array $tempPaths = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Create temporary directory structure for testing
        $tempDir = $this->createTempDirectory();
        $this->tempPaths[] = $tempDir;

        // Create a mock public directory with index.php as entry point
        $publicDir = $tempDir . '/public';
        mkdir($publicDir, 0777, true);

        $this->testEntryFile = $publicDir . '/index.php';
        file_put_contents($this->testEntryFile, '<?php // Test entry point');
    }

    protected function tearDown(): void
    {
        $this->cleanupTempFiles($this->tempPaths);
        parent::tearDown();
    }

    /**
     * @group pathresolver
     * @group initialization
     */
    public function testItInitializesWithValidEntryPointFile(): void
    {
        $pathResolver = PathResolver::fromEntryPoint($this->testEntryFile);

        $this->assertInstanceOf(PathResolver::class, $pathResolver);
        $this->assertIsString($pathResolver->appPath);
        $this->assertIsString($pathResolver->rootPath);
        $this->assertIsString($pathResolver->logsPath);
        $this->assertIsString($pathResolver->tempPath);
    }

    /**
     * @group pathresolver
     * @group initialization
     */
    public function testItSetsCorrectPathProperties(): void
    {
        $pathResolver = PathResolver::fromEntryPoint($this->testEntryFile);

        // appPath should be 2 directories up from entry point
        $expectedAppPath = dirname($this->testEntryFile, 2);
        $this->assertEquals($expectedAppPath, $pathResolver->appPath);

        // rootPath should be parent of appPath
        $expectedRootPath = dirname($expectedAppPath);
        $this->assertEquals($expectedRootPath, $pathResolver->rootPath);

        // logsPath should be appPath/logs
        $expectedLogsPath = $expectedAppPath . '/logs';
        $this->assertEquals($expectedLogsPath, $pathResolver->logsPath);

        // tempPath should be appPath/temp
        $expectedTempPath = $expectedAppPath . '/temp';
        $this->assertEquals($expectedTempPath, $pathResolver->tempPath);
    }

    /**
     * @group pathresolver
     * @group initialization
     */
    public function testItInitializesFromApplicationDirectory(): void
    {
        $appPath = dirname($this->testEntryFile, 2);
        $pathResolver = PathResolver::fromAppPath($appPath);

        self::assertSame($appPath, $pathResolver->appPath);
    }

    /**
     * @group pathresolver
     * @group initialization
     */
    public function testItThrowsExceptionForNonexistentEntryPointFile(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Entry point not found:');

        PathResolver::fromEntryPoint('/nonexistent/file.php');
    }

    /**
     * @group pathresolver
     * @group initialization
     */
    public function testEntryPointFactoryRejectsDirectory(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Entry point is not a file:');

        PathResolver::fromEntryPoint(dirname($this->testEntryFile));
    }

    /**
     * @group pathresolver
     * @group initialization
     */
    public function testAppPathFactoryRejectsFile(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Application path is not a directory:');

        PathResolver::fromAppPath($this->testEntryFile);
    }

    /**
     * @group pathresolver
     * @group path-conversion
     */
    public function testItConvertsAbsolutePathToRelativePath(): void
    {
        $pathResolver = PathResolver::fromEntryPoint($this->testEntryFile);

        $absolutePath = $pathResolver->appPath . '/config/servers.json';
        $relativePath = $pathResolver->toRelativePath($absolutePath);

        $this->assertEquals('config/servers.json', $relativePath);
    }

    /**
     * @group pathresolver
     * @group path-conversion
     */
    public function testItHandlesPathsOutsideAppDirectory(): void
    {
        $pathResolver = PathResolver::fromEntryPoint($this->testEntryFile);

        $outsidePath = '/tmp/external/file.txt';
        $relativePath = $pathResolver->toRelativePath($outsidePath);

        // Should return the path without leading slash
        $this->assertEquals('tmp/external/file.txt', $relativePath);
    }

    /**
     * @group pathresolver
     * @group path-normalization
     */
    public function testItHandlesTrailingSlashesInAppPath(): void
    {
        $pathResolver = PathResolver::fromEntryPoint($this->testEntryFile);

        // Test absolute path with trailing slash
        $pathWithTrailingSlash = rtrim($pathResolver->appPath, '/') . '/';
        $absolutePath = $pathWithTrailingSlash . 'logs/app.log';

        $relativePath = $pathResolver->toRelativePath($absolutePath);
        $this->assertEquals('logs/app.log', $relativePath);
    }

    /**
     * @group pathresolver
     * @group path-conversion
     */
    public function testItHandlesNestedDirectoryPaths(): void
    {
        $pathResolver = PathResolver::fromEntryPoint($this->testEntryFile);

        $nestedPath = $pathResolver->appPath . '/temp/api-cache/server_data/response.json';
        $relativePath = $pathResolver->toRelativePath($nestedPath);

        $this->assertEquals('temp/api-cache/server_data/response.json', $relativePath);
    }

    /**
     * @group pathresolver
     * @group path-conversion
     */
    public function testItHandlesPathsWithSpecialCharacters(): void
    {
        $pathResolver = PathResolver::fromEntryPoint($this->testEntryFile);

        $specialPath = $pathResolver->appPath . '/logs/app log (2024).txt';
        $relativePath = $pathResolver->toRelativePath($specialPath);

        $this->assertEquals('logs/app log (2024).txt', $relativePath);
    }

    /**
     * @group pathresolver
     * @group path-conversion
     */
    public function testItHandlesEmptyString(): void
    {
        $pathResolver = PathResolver::fromEntryPoint($this->testEntryFile);

        $relativePath = $pathResolver->toRelativePath('');

        $this->assertEquals('', $relativePath);
    }

    /**
     * @group pathresolver
     * @group path-conversion
     */
    public function testItRemovesLeadingSlashFromPathsOutsideApp(): void
    {
        $pathResolver = PathResolver::fromEntryPoint($this->testEntryFile);

        $externalPath = '/var/www/html/other-project/file.php';
        $relativePath = $pathResolver->toRelativePath($externalPath);

        // Should strip leading slash
        $this->assertStringStartsNotWith('/', $relativePath);
        $this->assertEquals('var/www/html/other-project/file.php', $relativePath);
    }

    /**
     * Web runs from public/ and cron from the crontab's working directory; a relative TEMP_DIR
     * or APP_LOG_PATH resolved against the cwd would split caches, locks and heartbeats between them.
     *
     * @group pathresolver
     */
    public function testOverridesResolveRelativeToTheAppPath(): void
    {
        $base = PathResolver::fromEntryPoint($this->testEntryFile);

        $paths = $base->withOverrides(' var/log/mata.log ', 'var/tmp');

        $this->assertSame($base->appPath . '/var/log/mata.log', $paths->appLogPath);
        $this->assertSame($base->appPath . '/var/log', $paths->logsPath);
        $this->assertSame($base->appPath . '/var/tmp', $paths->tempPath);
        $this->assertSame($base->appPath . '/var/tmp/api-cache', $paths->apiCachePath);
        $this->assertSame($base->bootLogPath, $paths->bootLogPath);

        $absolute = $base->withOverrides('/srv/mata/app.log', '');

        $this->assertSame('/srv/mata', $absolute->logsPath);
        $this->assertSame($base->tempPath, $absolute->tempPath);
    }

    /**
     * @group pathresolver
     */
    public function testEnsureDirectoriesCreateMissingOverrides(): void
    {
        $paths = PathResolver::fromEntryPoint($this->testEntryFile)->withOverrides('var/log/app.log', 'var/tmp');

        $paths->ensureLogsDirectory();
        $paths->ensureTempDirectory();

        $this->assertDirectoryExists($paths->logsPath);
        $this->assertDirectoryExists($paths->tempPath);
    }

    /**
     * Without it, heartbeats are lost and the dashboard reports every cron job as not running.
     *
     * @group pathresolver
     */
    public function testEnsureCronLogsDirectoryCreatesItUnderTheOverriddenLogsPath(): void
    {
        $paths = PathResolver::fromEntryPoint($this->testEntryFile)->withOverrides('var/log/app.log', 'var/tmp');

        $paths->ensureCronLogsDirectory();

        $this->assertSame($paths->appPath . '/var/log/cron', $paths->cronLogsPath);
        $this->assertDirectoryExists($paths->cronLogsPath);
    }

    /**
     * First boot and `make setup` copy these templates from the resolved example path; a
     * moved or renamed template directory would leave a fresh install without config.
     *
     * @group pathresolver
     */
    public function testConfigExamplePathHoldsTheTemplatesSetupCopies(): void
    {
        $pathResolver = PathResolver::fromAppPath(\dirname(__DIR__, 4));

        foreach (['config.env', 'db.config.env', 'mail.config.env', 'servers.json', 'servers.secrets.json'] as $file) {
            $this->assertFileExists($pathResolver->configExamplePath . '/' . $file . '.example');
        }
    }
}
