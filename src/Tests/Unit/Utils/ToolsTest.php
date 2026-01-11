<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Utils;

use Mata\Tests\TestCase;
use Mata\Utils\Tools;
use RuntimeException;

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
    public function testItConvertsBytesToHumanReadableFormat(): void
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
            $result = $this->tools->convertSize($bytes);
            $this->assertEquals($expected, $result, "Failed converting {$bytes} bytes");
        }
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
     * @group encryption
     */
    public function testItEncryptsAndDecryptsData(): void
    {
        $plaintext = 'Sensitive monitoring data';
        $key = 'test-encryption-key-32-characters!';

        $encrypted = $this->tools->encrypt($plaintext, $key);
        $decrypted = $this->tools->decrypt($encrypted, $key);

        $this->assertNotEquals($plaintext, $encrypted);
        $this->assertEquals($plaintext, $decrypted);
    }

    /**
     * @group tools
     * @group encryption
     */
    public function testItProducesDifferentEncryptedOutputEachTime(): void
    {
        $plaintext = 'Test data';
        $key = 'test-key-32-characters-long-here!';

        $encrypted1 = $this->tools->encrypt($plaintext, $key);
        $encrypted2 = $this->tools->encrypt($plaintext, $key);

        // Different IVs mean different encrypted output
        $this->assertNotEquals($encrypted1, $encrypted2);

        // But both should decrypt to the same plaintext
        $this->assertEquals($plaintext, $this->tools->decrypt($encrypted1, $key));
        $this->assertEquals($plaintext, $this->tools->decrypt($encrypted2, $key));
    }

    /**
     * @group tools
     * @group auth
     */
    public function testItBuildsHmacAuthToken(): void
    {
        $server = 'node-1';
        $apiKey = 'test-api-key';
        $timestamp = 1700000000;
        $nonce = 'nonce-123';
        $payload = $server . '|' . $timestamp . '|' . $nonce;
        $signature = hash_hmac('sha256', $payload, $apiKey);

        $token = $this->tools->buildHmacAuthToken($server, $apiKey, $timestamp, $nonce);

        $this->assertSame($server . '|' . $timestamp . '|' . $nonce . '|' . $signature, $token);
    }

    /**
     * @group tools
     * @group formatting
     */
    public function testItFormatsNumbersWithUnits(): void
    {
        $testCases = [
            [0, '0'],
            [1, '1'],
            [999, '999'],
            [1000, '1k'],
            [1500, '1.5k'],
            [10000, '10k'],
            [1000000, '1M'],
            [2500000, '2.5M'],
            [10000000, '10M'],
        ];

        foreach ($testCases as [$number, $expected]) {
            $result = $this->tools->formatNumber($number);
            $this->assertEquals($expected, $result, "Failed formatting number {$number}");
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

    /**
     * @group tools
     * @group formatting
     */
    public function testItHandlesLargeNumbersInFormatNumber(): void
    {
        $result = $this->tools->formatNumber(999999999);
        $this->assertEquals('1000M', $result);
    }

    /**
     * @group tools
     * @group formatting
     */
    public function testItHandlesFloatsInFormatNumber(): void
    {
        $result = $this->tools->formatNumber(1234.56);
        $this->assertEquals('1.2k', $result);

        $result2 = $this->tools->formatNumber(1234567.89);
        $this->assertEquals('1.2M', $result2);
    }

    /**
     * @group tools
     * @group encryption
     */
    public function testDecryptThrowsExceptionForInvalidData(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Decryption failed');

        $key = 'test-key-32-characters-long-here!';
        $this->tools->decrypt('invalid-encrypted-data', $key);
    }
}
