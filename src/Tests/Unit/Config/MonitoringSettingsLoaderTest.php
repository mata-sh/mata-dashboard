<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Config;

use Mata\Config\MonitoringSettingsLoader;
use Mata\Models\Dto\EventRecordResult;
use Mata\Services\Event\EventRecorderService;
use Mata\Tests\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * @covers \Mata\Config\MonitoringSettingsLoader
 *
 * @internal
 */
final class MonitoringSettingsLoaderTest extends TestCase
{
    private EventRecorderService&MockObject $eventRecorder;
    private MonitoringSettingsLoader $loader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->eventRecorder = $this->createMock(EventRecorderService::class);

        // MonitoringSettingsLoader accepts optional parameters for alertManager, db, and settingsSync
        // We only need eventRecorder for validation tests
        $this->loader = new MonitoringSettingsLoader($this->eventRecorder);
    }

    // ==================== HTTPS Validation Tests ====================

    public function testValidateServersConfigHttpsUrlIsValid(): void
    {
        $config = [
            'production-server' => [
                'title' => 'Production Server',
                'api_url' => 'https://api.example.com/mata-node/api',
                'api_key' => 'secure_key_123',
            ],
        ];

        $result = $this->loader->validateServersConfig($config);

        $this->assertTrue($result['is_valid']);
        $this->assertEmpty($result['errors']);
    }

    public function testValidateServersConfigHttpWithoutAllowHttpFlagFails(): void
    {
        $config = [
            'dev-server' => [
                'title' => 'Dev Server',
                'api_url' => 'http://localhost:3301/mata-node/api',
                'api_key' => 'dev_key_123',
            ],
        ];

        $result = $this->loader->validateServersConfig($config);

        $this->assertFalse($result['is_valid']);
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('must use HTTPS protocol', $result['errors'][0]);
        $this->assertStringContainsString("set 'allow_http': \"on\"", $result['errors'][0]);
    }

    public function testValidateServersConfigHttpWithAllowHttpFlagIsValid(): void
    {
        $config = [
            'dev-server' => [
                'title' => 'Dev Server',
                'api_url' => 'http://localhost:3301/mata-node/api',
                'api_key' => 'dev_key_123',
                'allow_http' => 'on',
            ],
        ];

        // Expect warning to be logged via recordEvent
        $this->eventRecorder
            ->expects($this->once())
            ->method('recordEvent')
            ->willReturn(new EventRecordResult(
                eventId: 1,
                alarmId: null,
                serverId: null,
                appId: null,
                serverLabel: null,
                appLabel: null
            ));

        $result = $this->loader->validateServersConfig($config);

        $this->assertTrue($result['is_valid']);
        $this->assertEmpty($result['errors']);
    }

    public function testValidateServersConfigHttpWithAllowHttpAndSuppressWarning(): void
    {
        $config = [
            'internal-server' => [
                'title' => 'Internal Server',
                'api_url' => 'http://192.168.1.100/mata-node/api',
                'api_key' => 'internal_key_123',
                'allow_http' => 'on',
                'suppress_http_warning' => 'on',
            ],
        ];

        // No warning should be logged
        $this->eventRecorder
            ->expects($this->never())
            ->method('recordEvent');

        $result = $this->loader->validateServersConfig($config);

        $this->assertTrue($result['is_valid']);
        $this->assertEmpty($result['errors']);
    }

    public function testValidateServersConfigHttpWithInvalidAllowHttpValue(): void
    {
        $config = [
            'server' => [
                'title' => 'Server',
                'api_url' => 'http://example.com/api',
                'api_key' => 'key123',
                'allow_http' => 'maybe', // Invalid value
            ],
        ];

        $result = $this->loader->validateServersConfig($config);

        $this->assertFalse($result['is_valid']);
        $this->assertStringContainsString('must use HTTPS protocol', $result['errors'][0]);
    }

    public function testValidateServersConfigHttpWithAllowHttpOff(): void
    {
        $config = [
            'server' => [
                'title' => 'Server',
                'api_url' => 'http://example.com/api',
                'api_key' => 'key123',
                'allow_http' => 'off',
            ],
        ];

        $result = $this->loader->validateServersConfig($config);

        $this->assertFalse($result['is_valid']);
        $this->assertStringContainsString('must use HTTPS protocol', $result['errors'][0]);
    }

    // ==================== URL Format Validation Tests ====================

    public function testValidateServersConfigInvalidUrlFormat(): void
    {
        $config = [
            'server' => [
                'title' => 'Server',
                'api_url' => 'not-a-valid-url',
                'api_key' => 'key123',
            ],
        ];

        $result = $this->loader->validateServersConfig($config);

        $this->assertFalse($result['is_valid']);
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('invalid API URL format', $result['errors'][0]);
    }

    public function testValidateServersConfigFtpUrlIsRejected(): void
    {
        $config = [
            'server' => [
                'title' => 'Server',
                'api_url' => 'ftp://example.com/api',
                'api_key' => 'key123',
            ],
        ];

        $result = $this->loader->validateServersConfig($config);

        $this->assertFalse($result['is_valid']);
        $this->assertStringContainsString('must use HTTPS protocol (found: ftp://)', $result['errors'][0]);
    }

    public function testValidateServersConfigEmptyApiUrl(): void
    {
        $config = [
            'server' => [
                'title' => 'Server',
                'api_url' => '',
                'api_key' => 'key123',
            ],
        ];

        $result = $this->loader->validateServersConfig($config);

        $this->assertFalse($result['is_valid']);
        $this->assertStringContainsString('missing required field: api_url', $result['errors'][0]);
    }

    // ==================== Color Validation Tests ====================

    public function testValidateServersConfigValidHexColor(): void
    {
        $config = [
            'server' => [
                'title' => 'Server',
                'api_url' => 'https://example.com/api',
                'api_key' => 'key123',
                'primary_color' => '#28a745',
            ],
        ];

        $result = $this->loader->validateServersConfig($config);

        $this->assertTrue($result['is_valid']);
        $this->assertEmpty($result['errors']);
    }

    public function testValidateServersConfigInvalidColorFormatNoHash(): void
    {
        $config = [
            'server' => [
                'title' => 'Server',
                'api_url' => 'https://example.com/api',
                'api_key' => 'key123',
                'primary_color' => '28a745',
            ],
        ];

        $result = $this->loader->validateServersConfig($config);

        $this->assertFalse($result['is_valid']);
        $this->assertStringContainsString('invalid primary_color format', $result['errors'][0]);
        $this->assertStringContainsString('should be #RRGGBB', $result['errors'][0]);
    }

    public function testValidateServersConfigInvalidColorFormatTooShort(): void
    {
        $config = [
            'server' => [
                'title' => 'Server',
                'api_url' => 'https://example.com/api',
                'api_key' => 'key123',
                'primary_color' => '#28a',
            ],
        ];

        $result = $this->loader->validateServersConfig($config);

        $this->assertFalse($result['is_valid']);
        $this->assertStringContainsString('invalid primary_color format', $result['errors'][0]);
    }

    public function testValidateServersConfigInvalidColorFormatTooLong(): void
    {
        $config = [
            'server' => [
                'title' => 'Server',
                'api_url' => 'https://example.com/api',
                'api_key' => 'key123',
                'primary_color' => '#28a74512',
            ],
        ];

        $result = $this->loader->validateServersConfig($config);

        $this->assertFalse($result['is_valid']);
        $this->assertStringContainsString('invalid primary_color format', $result['errors'][0]);
    }

    public function testValidateServersConfigInvalidColorFormatInvalidChars(): void
    {
        $config = [
            'server' => [
                'title' => 'Server',
                'api_url' => 'https://example.com/api',
                'api_key' => 'key123',
                'primary_color' => '#gghhii',
            ],
        ];

        $result = $this->loader->validateServersConfig($config);

        $this->assertFalse($result['is_valid']);
        $this->assertStringContainsString('invalid primary_color format', $result['errors'][0]);
    }

    public function testValidateServersConfigMissingPrimaryColorIsValid(): void
    {
        $config = [
            'server' => [
                'title' => 'Server',
                'api_url' => 'https://example.com/api',
                'api_key' => 'key123',
            ],
        ];

        $result = $this->loader->validateServersConfig($config);

        $this->assertTrue($result['is_valid']);
        $this->assertEmpty($result['errors']);
    }

    // ==================== Required Fields Tests ====================

    public function testValidateServersConfigMissingTitle(): void
    {
        $config = [
            'server' => [
                'api_url' => 'https://example.com/api',
                'api_key' => 'key123',
            ],
        ];

        $result = $this->loader->validateServersConfig($config);

        $this->assertFalse($result['is_valid']);
        $this->assertStringContainsString('missing required field: title', $result['errors'][0]);
    }

    public function testValidateServersConfigMissingApiKey(): void
    {
        $config = [
            'server' => [
                'title' => 'Server',
                'api_url' => 'https://example.com/api',
            ],
        ];

        $result = $this->loader->validateServersConfig($config);

        $this->assertFalse($result['is_valid']);
        $this->assertStringContainsString('missing required field: api_key', $result['errors'][0]);
    }

    public function testValidateServersConfigMultipleErrors(): void
    {
        $config = [
            'server' => [
                'api_url' => 'http://example.com/api', // HTTP without allow_http
                'primary_color' => 'invalid-color',      // Invalid color
            ],
        ];

        $result = $this->loader->validateServersConfig($config);

        $this->assertFalse($result['is_valid']);
        $this->assertGreaterThanOrEqual(3, count($result['errors'])); // title, api_key, HTTP, color
    }

    // ==================== Multiple Servers Tests ====================

    public function testValidateServersConfigMultipleServersAllValid(): void
    {
        $config = [
            'server1' => [
                'title' => 'Server 1',
                'api_url' => 'https://server1.example.com/api',
                'api_key' => 'key1',
            ],
            'server2' => [
                'title' => 'Server 2',
                'api_url' => 'https://server2.example.com/api',
                'api_key' => 'key2',
                'primary_color' => '#ff0000',
            ],
        ];

        $result = $this->loader->validateServersConfig($config);

        $this->assertTrue($result['is_valid']);
        $this->assertEmpty($result['errors']);
    }

    public function testValidateServersConfigMultipleServersMixedValidity(): void
    {
        $config = [
            'valid-server' => [
                'title' => 'Valid Server',
                'api_url' => 'https://valid.example.com/api',
                'api_key' => 'key1',
            ],
            'invalid-server' => [
                'title' => 'Invalid Server',
                'api_url' => 'http://invalid.example.com/api', // HTTP without flag
                'api_key' => 'key2',
            ],
        ];

        $result = $this->loader->validateServersConfig($config);

        $this->assertFalse($result['is_valid']);
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('invalid-server', $result['errors'][0]);
    }

    // ==================== Edge Cases ====================

    public function testValidateServersConfigEmptyConfig(): void
    {
        $config = [];

        $result = $this->loader->validateServersConfig($config);

        $this->assertFalse($result['is_valid']);
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('Server configuration is empty', $result['errors'][0]);
    }

    public function testValidateServersConfigServerConfigNotArray(): void
    {
        $config = [
            'server' => 'not-an-array',
        ];

        $result = $this->loader->validateServersConfig($config);

        $this->assertFalse($result['is_valid']);
        $this->assertStringContainsString('configuration must be an array', $result['errors'][0]);
    }

    public function testValidateServersConfigHttpsWithMixedCase(): void
    {
        // Host can be mixed case; scheme stays lower-case for FILTER_VALIDATE_URL.
        $config = [
            'server' => [
                'title' => 'Server',
                'api_url' => 'https://Example.Com/api', // Only host can be mixed case
                'api_key' => 'key123',
            ],
        ];

        $result = $this->loader->validateServersConfig($config);

        $this->assertTrue($result['is_valid']);
        $this->assertEmpty($result['errors']);
    }

    public function testValidateServersConfigAllAllowHttpBooleanVariations(): void
    {
        $validVariations = ['on', 'true', 'yes', 'enabled', '1'];

        foreach ($validVariations as $variation) {
            $config = [
                "server-{$variation}" => [
                    'title' => 'Server',
                    'api_url' => 'http://example.com/api',
                    'api_key' => 'key123',
                    'allow_http' => $variation,
                    'suppress_http_warning' => 'on',
                ],
            ];

            $result = $this->loader->validateServersConfig($config);

            $this->assertTrue(
                $result['is_valid'],
                "Expected allow_http='{$variation}' to be valid"
            );
        }
    }

    public function testValidateServersConfigAllSuppressWarningBooleanVariations(): void
    {
        $validVariations = ['on', 'true', 'yes', 'enabled', '1'];

        // Should not log warning
        $this->eventRecorder
            ->expects($this->never())
            ->method('recordEvent');

        foreach ($validVariations as $variation) {
            $config = [
                "server-{$variation}" => [
                    'title' => 'Server',
                    'api_url' => 'http://example.com/api',
                    'api_key' => 'key123',
                    'allow_http' => 'on',
                    'suppress_http_warning' => $variation,
                ],
            ];

            $result = $this->loader->validateServersConfig($config);

            $this->assertTrue(
                $result['is_valid'],
                "Expected suppress_http_warning='{$variation}' to suppress warnings"
            );
        }
    }

    public function testValidateServersConfigHexColorCaseInsensitive(): void
    {
        $config = [
            'server' => [
                'title' => 'Server',
                'api_url' => 'https://example.com/api',
                'api_key' => 'key123',
                'primary_color' => '#ABCDEF',
            ],
        ];

        $result = $this->loader->validateServersConfig($config);

        $this->assertTrue($result['is_valid']);
        $this->assertEmpty($result['errors']);
    }
}
