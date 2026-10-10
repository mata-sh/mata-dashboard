<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Utils;

use Mata\Tests\TestCase;
use Mata\Utils\Tools;

/**
 * Test suite for Tools utility class.
 *
 * @covers \Mata\Utils\Tools
 *
 * @internal
 */
class ToolsTest extends TestCase
{
    private Tools $tools;
    private string $testAppPath;
    private string $testPublicPath;
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Create temporary application path for testing
        $this->testAppPath = $this->createTempDirectory();
        $this->tempFiles[] = $this->testAppPath;

        // Create public directory structure for icon tests
        $this->testPublicPath = $this->testAppPath . '/public';
        mkdir($this->testPublicPath, 0777, true);
        mkdir($this->testPublicPath . '/assets/icons', 0777, true);

        $this->tools = new Tools($this->testAppPath, $this->testPublicPath);
    }

    protected function tearDown(): void
    {
        $this->cleanupTempFiles($this->tempFiles);
        parent::tearDown();
    }

    /**
     * @group tools
     * @group initialization
     */
    public function testItInitializesWithAppPath(): void
    {
        $this->assertInstanceOf(Tools::class, $this->tools);
    }

    /**
     * @group tools
     * @group initialization
     */
    public function testItUsesDefaultPublicPathWhenNotProvided(): void
    {
        $tools = new Tools($this->testAppPath);
        $this->assertInstanceOf(Tools::class, $tools);
    }

    /**
     * @group tools
     * @group formatting
     */
    public function testItFormatsBytesForDisplay(): void
    {
        $testCases = [
            [0, '0 KB'],
            [1024, '1 KB'],
            [1536, '1.5 KB'], // 1.5 KB
            [1048576, '1 MB'], // 1 MB
            [1572864, '1.5 MB'], // 1.5 MB
            [1073741824, '1 GB'], // 1 GB
            [5368709120, '5 GB'], // 5 GB
            [107374182400, '100 GB'], // 100 GB
            [1099511627776, '1 TB'], // 1 TB
        ];

        foreach ($testCases as [$bytes, $expected]) {
            $result = $this->tools->formatBytes($bytes);
            $this->assertEquals($expected, $result, "Failed converting {$bytes} bytes");
        }
    }

    public function testItFormatsKilobytesForDisplay(): void
    {
        $this->assertSame('3.32 MB', $this->tools->formatKilobytes(3400));
        $this->assertSame('16 GB', $this->tools->formatKilobytes(16 * 1024 * 1024));
    }

    /**
     * @group tools
     * @group formatting
     */
    public function testItFormatsDurationCorrectly(): void
    {
        $testCases = [
            [-1, '∞'], // Special case for infinite
            [0, '0 seconds'],
            [1, '1 second'],
            [30, '30 seconds'],
            [60, '1 minute'],
            [90, '1 minute'],
            [120, '2 minutes'],
            [3600, '1 hour'],
            [7200, '2 hours'],
            [86400, '1 day'],
            [172800, '2 days'],
        ];

        foreach ($testCases as [$seconds, $expected]) {
            $result = $this->tools->formatDuration($seconds);
            $this->assertEquals($expected, $result, "Failed formatting {$seconds} seconds");
        }
    }

    /**
     * @group tools
     * @group icons
     */
    public function testItReturnsEmptyStringForMissingIconFiles(): void
    {
        $result = $this->tools->icon('nonexistent-icon', 24);
        $this->assertEquals('', $result);
    }

    /**
     * @group tools
     * @group icons
     */
    public function testItLoadsAndModifiesSvgIcons(): void
    {
        // Create test icon directory and file
        $svgContent = '<svg width="24" height="24" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/></svg>';
        $iconFile = $this->testPublicPath . '/assets/icons/test-icon.svg';
        file_put_contents($iconFile, $svgContent);

        $result = $this->tools->icon('test-icon', 32, 'custom-class');

        $this->assertStringContainsString('width="32"', $result);
        $this->assertStringContainsString('height="32"', $result);
        $this->assertStringContainsString('class="icon custom-class"', $result);
        $this->assertStringContainsString('<circle cx="12" cy="12" r="10"/>', $result);
    }

    /**
     * @group tools
     * @group icons
     */
    public function testItHandlesSubfolderIcons(): void
    {
        // Create test icon in subfolder
        $iconDir = $this->testPublicPath . '/assets/icons/animated';
        mkdir($iconDir, 0777, true);

        $svgContent = '<svg><path d="M12 2L2 7v10c0 5.55 3.84 9.75 9 10.25"/></svg>';
        $iconFile = $iconDir . '/spinner.svg';
        file_put_contents($iconFile, $svgContent);

        $result = $this->tools->icon('spinner', 16, '', 'animated');

        $this->assertStringContainsString('width="16"', $result);
        $this->assertStringContainsString('height="16"', $result);
        $this->assertStringContainsString('<path d="M12 2L2 7v10c0 5.55 3.84 9.75 9 10.25"/>', $result);
    }

    /**
     * @group tools
     * @group icons
     */
    public function testItAddsIconClassToSvg(): void
    {
        $svgContent = '<svg><rect width="100" height="100"/></svg>';
        $iconFile = $this->testPublicPath . '/assets/icons/square.svg';
        file_put_contents($iconFile, $svgContent);

        $result = $this->tools->icon('square', 24, 'my-custom-class');

        $this->assertStringContainsString('class="icon my-custom-class"', $result);
    }
}
