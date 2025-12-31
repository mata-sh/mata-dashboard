<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Utils;

use InvalidArgumentException;
use Watcher\Tests\TestCase;
use Watcher\Utils\PathResolver;

/**
 * Test suite for PathResolver utility class.
 *
 * @covers \Watcher\Utils\PathResolver
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
        $pathResolver = new PathResolver($this->testEntryFile);

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
        $pathResolver = new PathResolver($this->testEntryFile);

        // appPath should be 2 directories up from entry point
        $expectedAppPath = dirname($this->testEntryFile, 2);
        $this->assertEquals($expectedAppPath, $pathResolver->appPath);

        // rootPath should be parent of appPath
        $expectedRootPath = dirname($expectedAppPath);
        $this->assertEquals($expectedRootPath, $pathResolver->rootPath);

        // logsPath should be appPath/logs
        $expectedLogsPath = $expectedAppPath . DIRECTORY_SEPARATOR . 'logs';
        $this->assertEquals($expectedLogsPath, $pathResolver->logsPath);

        // tempPath should be appPath/temp
        $expectedTempPath = $expectedAppPath . DIRECTORY_SEPARATOR . 'temp';
        $this->assertEquals($expectedTempPath, $pathResolver->tempPath);
    }

    /**
     * @group pathresolver
     * @group initialization
     */
    public function testItThrowsExceptionForNonexistentEntryPointFile(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Entry point file not found:');

        new PathResolver('/nonexistent/file.php');
    }

    /**
     * @group pathresolver
     * @group path-conversion
     */
    public function testItConvertsAbsolutePathToRelativePath(): void
    {
        $pathResolver = new PathResolver($this->testEntryFile);

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
        $pathResolver = new PathResolver($this->testEntryFile);

        $outsidePath = '/tmp/external/file.txt';
        $relativePath = $pathResolver->toRelativePath($outsidePath);

        // Should return the path without leading slash
        $this->assertEquals('tmp/external/file.txt', $relativePath);
    }

    /**
     * @group pathresolver
     * @group path-normalization
     */
    public function testItNormalizesPathSeparators(): void
    {
        $pathResolver = new PathResolver($this->testEntryFile);

        // Test with Windows-style backslashes
        $windowsPath = $pathResolver->appPath . '\config\database\mysql.env';
        $relativePath = $pathResolver->toRelativePath($windowsPath);

        // Should normalize to Unix separators
        $this->assertEquals('config/database/mysql.env', $relativePath);
    }

    /**
     * @group pathresolver
     * @group path-normalization
     */
    public function testItHandlesTrailingSlashesInAppPath(): void
    {
        $pathResolver = new PathResolver($this->testEntryFile);

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
        $pathResolver = new PathResolver($this->testEntryFile);

        $nestedPath = $pathResolver->appPath . '/temp/api_cache/server_data/response.json';
        $relativePath = $pathResolver->toRelativePath($nestedPath);

        $this->assertEquals('temp/api_cache/server_data/response.json', $relativePath);
    }

    /**
     * @group pathresolver
     * @group path-conversion
     */
    public function testItHandlesPathsWithSpecialCharacters(): void
    {
        $pathResolver = new PathResolver($this->testEntryFile);

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
        $pathResolver = new PathResolver($this->testEntryFile);

        $relativePath = $pathResolver->toRelativePath('');

        $this->assertEquals('', $relativePath);
    }

    /**
     * @group pathresolver
     * @group path-conversion
     */
    public function testItRemovesLeadingSlashFromPathsOutsideApp(): void
    {
        $pathResolver = new PathResolver($this->testEntryFile);

        $externalPath = '/var/www/html/other-project/file.php';
        $relativePath = $pathResolver->toRelativePath($externalPath);

        // Should strip leading slash
        $this->assertStringStartsNotWith('/', $relativePath);
        $this->assertEquals('var/www/html/other-project/file.php', $relativePath);
    }

    /**
     * @group pathresolver
     * @group initialization
     */
    public function testItUsesCorrectDirectorySeparator(): void
    {
        $pathResolver = new PathResolver($this->testEntryFile);

        // Paths should use the system's directory separator
        $this->assertStringContainsString(DIRECTORY_SEPARATOR, $pathResolver->logsPath);
        $this->assertStringContainsString(DIRECTORY_SEPARATOR, $pathResolver->tempPath);
    }
}
