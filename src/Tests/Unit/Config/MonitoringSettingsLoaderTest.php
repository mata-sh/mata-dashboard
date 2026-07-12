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

        // MonitoringSettingsLoader accepts optional parameters for alarmManager, db, and settingsSync
        // We only need eventRecorder for validation tests
        $this->loader = new MonitoringSettingsLoader($this->eventRecorder);
    }

    // ==================== HTTPS Validation Tests ====================

    public function testValidateServersConfigHttpsUrlIsValid(): void
    {
        $config = [
            'production-server' => [
                'title' => 'Production Server',
                'api_url' => 'https://mata.api.example.com/',
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
                'api_url' => 'http://localhost:3310/mata-node',
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
                'api_url' => 'http://localhost:3310/mata-node',
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
                'api_url' => 'http://192.168.1.100/mata-node',
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
                'api_url' => 'http://api.example.com/',
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
                'api_url' => 'http://api.example.com/',
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
                'api_url' => 'ftp://api.example.com',
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
                'api_url' => 'https://api.example.com',
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
                'api_url' => 'https://api.example.com',
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
                'api_url' => 'https://api.example.com',
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
                'api_url' => 'https://api.example.com',
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
                'api_url' => 'https://api.example.com',
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
                'api_url' => 'https://api.example.com',
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
                'api_url' => 'https://api.example.com',
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
                'api_url' => 'https://api.example.com',
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
                'api_url' => 'http://api.example.com', // HTTP without allow_http
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
                'api_url' => 'https://server1.api.example.com',
                'api_key' => 'key1',
            ],
            'server2' => [
                'title' => 'Server 2',
                'api_url' => 'https://server2.api.example.com',
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
                'api_url' => 'https://valid.api.example.com',
                'api_key' => 'key1',
            ],
            'invalid-server' => [
                'title' => 'Invalid Server',
                'api_url' => 'http://invalid.api.example.com', // HTTP without flag
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
                'api_url' => 'https://api.Example.Com', // Only host can be mixed case
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
                    'api_url' => 'http://api.example.com',
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
                    'api_url' => 'http://api.example.com',
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
                'api_url' => 'https://api.example.com',
                'api_key' => 'key123',
                'primary_color' => '#ABCDEF',
            ],
        ];

        $result = $this->loader->validateServersConfig($config);

        $this->assertTrue($result['is_valid']);
        $this->assertEmpty($result['errors']);
    }

    public function testDefaultFatalErrorEventIntervalIsConfigured(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'mata-intervals-');
        self::assertNotFalse($path);
        file_put_contents($path, '{"unused": true}');

        try {
            $config = $this->loader->loadIntervalsConfig($path);
        } finally {
            unlink($path);
        }

        $intervals = $config['events']['min_event_interval_minutes'];
        $this->assertSame(72, $intervals['fatal_errors_occurring']);
        $this->assertSame(60, $intervals['_default']);
    }

    public function testDataRetentionDefaultsContainSeparateCategories(): void
    {
        $config = $this->loadDataRetentionConfig(['metrics' => ['raw_retention_days' => 12]]);

        $this->assertSame(12, $config['metrics']['raw_retention_days']);
        $this->assertSame(90, $config['metrics']['hourly_retention_days']);
        $this->assertSame(365, $config['metrics']['daily_retention_days']);
        $this->assertSame(180, $config['events']['retention_days']);
        $this->assertSame(365, $config['alarms']['retention_days']);
        $this->assertSame(15, $config['ingestion_health']['stall_minutes']);
    }

    public function testDataRetentionPartialNestedConfigPreservesDefaults(): void
    {
        $config = $this->loadDataRetentionConfig([
            'aggregation' => ['hourly' => ['lag_minutes' => 10]],
            'ingestion_health' => ['log_rotate_files' => 9],
        ]);

        $this->assertSame(10, $config['aggregation']['hourly']['lag_minutes']);
        $this->assertSame(24, $config['aggregation']['hourly']['catch_up_hours']);
        $this->assertSame(30, $config['aggregation']['daily']['lag_minutes']);
        $this->assertSame(9, $config['ingestion_health']['log_rotate_files']);
        $this->assertSame(5, $config['ingestion_health']['log_rotate_megabytes']);
    }

    public function testDataRetentionNormalizesNegativeRetentionAndPreservesDisabledZero(): void
    {
        $config = $this->loadDataRetentionConfig([
            'metrics' => [
                'raw_retention_days' => -1,
                'hourly_retention_days' => 0,
            ],
            'events' => ['retention_days' => '-2'],
            'alarms' => ['retention_days' => '0'],
        ]);

        $this->assertSame(0, $config['metrics']['raw_retention_days']);
        $this->assertSame(0, $config['metrics']['hourly_retention_days']);
        $this->assertSame(0, $config['events']['retention_days']);
        $this->assertSame(0, $config['alarms']['retention_days']);
    }

    /** @param array<string,mixed> $config */
    private function loadDataRetentionConfig(array $config): array
    {
        $path = tempnam(sys_get_temp_dir(), 'mata-retention-');
        self::assertNotFalse($path);
        file_put_contents($path, json_encode($config, JSON_THROW_ON_ERROR));

        try {
            return $this->loader->loadDataRetentionConfig($path);
        } finally {
            unlink($path);
        }
    }
}
