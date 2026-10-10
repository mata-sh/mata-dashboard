<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Config;

use InvalidArgumentException;
use Mata\Config\Monitoring\Loader\DataRetentionConfigLoader;
use Mata\Config\Monitoring\Loader\IntervalsConfigLoader;
use Mata\Config\Monitoring\Loader\MenuConfigLoader;
use Mata\Config\Monitoring\Loader\NotificationRulesConfigLoader;
use Mata\Config\Monitoring\Loader\ProbesConfigLoader;
use Mata\Config\Monitoring\Loader\ServersConfigLoader;
use Mata\Config\Monitoring\Loader\ThresholdsConfigLoader;
use Mata\Config\Monitoring\Loader\TtlConfigLoader;
use Mata\Config\Monitoring\MonitoringConfigLoader;
use Mata\Config\Monitoring\Section\AlarmRateLimit;
use Mata\Config\Monitoring\Section\AlarmRateLimitsConfig;
use Mata\Config\Monitoring\Section\ChartsConfig;
use Mata\Config\Monitoring\Section\DataRetentionConfig;
use Mata\Config\Monitoring\Section\NotificationRulesConfig;
use Mata\Config\Monitoring\Section\ProbesConfig;
use Mata\Services\Event\Dto\EventRecordResult;
use Mata\Services\Event\EventRecorderService;
use Mata\Services\Navigation\MenuBuilder;
use Mata\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;

/**
 * @internal
 *
 * @coversNothing
 */
final class MonitoringConfigLoadersTest extends TestCase
{
    private EventRecorderService $eventRecorder;
    private ServersConfigLoader $loader;
    private MonitoringConfigLoader $manager;
    private TtlConfigLoader $ttlLoader;
    private NotificationRulesConfigLoader $notificationRulesLoader;
    private ThresholdsConfigLoader $thresholdsLoader;
    private IntervalsConfigLoader $intervalsLoader;
    private DataRetentionConfigLoader $dataRetentionLoader;
    private MenuConfigLoader $menuLoader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->eventRecorder = $this->createStub(EventRecorderService::class);

        $this->initializeLoaders();
    }

    public function testLoadAllConfigsUsesConfigurationRootForEveryRuntimeEnvironment(): void
    {
        $configPath = $this->createTempDirectory();
        file_put_contents($configPath . '/servers.json', json_encode([
            'server' => [
                'title' => 'Server',
                'api_url' => 'https://server.example.test/api',
                'api_key' => 'key123',
            ],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($configPath . '/ttl.json', '{"apps": 1}');
        file_put_contents($configPath . '/thresholds.json', '{"server_data": {}}');
        file_put_contents($configPath . '/intervals.json', '{"events": {}}');
        file_put_contents($configPath . '/data_retention.json', '{"retention": {}}');
        file_put_contents($configPath . '/probes.json', '{"max_retries": 3}');

        try {
            $config = $this->manager->load($configPath, $this->alarmRateLimits());
        } finally {
            $this->cleanupTempFiles([$configPath]);
        }

        $this->assertArrayHasKey('server', $config->servers->values);
        $this->assertSame(3, $config->probes->maxRetries);
        $this->assertSame(5, $config->probes->timeoutSeconds, 'omitted probe keys fall back to defaults');
        $this->assertSame(
            ['enabled' => true, 'channels' => ['email']],
            $config->notificationRules->defaults
        );
    }

    public function testFallbackDefaultsKeepTheCompleteMonitoringShape(): void
    {
        $this->useEventRecorderMock();
        // Required files only; missing optional files (probes, notification rules, menu) are silent.
        $this->eventRecorder
            ->expects($this->exactly(5))
            ->method('recordEvent')
            ->willReturn(new EventRecordResult(1, null, null, null, null, null));
        $configPath = $this->createTempDirectory();
        $manager = new MonitoringConfigLoader(
            servers: new ServersConfigLoader(events: $this->eventManagerFor($this->eventRecorder), fallbackOnFailure: true),
            ttl: new TtlConfigLoader(events: $this->eventManagerFor($this->eventRecorder), fallbackOnFailure: true),
            dataRetention: new DataRetentionConfigLoader(events: $this->eventManagerFor($this->eventRecorder), fallbackOnFailure: true),
            intervals: new IntervalsConfigLoader(events: $this->eventManagerFor($this->eventRecorder), fallbackOnFailure: true),
            probes: new ProbesConfigLoader(events: $this->eventManagerFor($this->eventRecorder), fallbackOnFailure: true),
            notificationRules: new NotificationRulesConfigLoader(events: $this->eventManagerFor($this->eventRecorder), fallbackOnFailure: true),
            thresholds: new ThresholdsConfigLoader(events: $this->eventManagerFor($this->eventRecorder), fallbackOnFailure: true),
            menu: new MenuConfigLoader($this->eventManagerFor($this->eventRecorder), MenuBuilder::MENU_KEYS),
        );

        try {
            $config = $manager->load($configPath, $this->alarmRateLimits());
        } finally {
            $this->cleanupTempFiles([$configPath]);
        }

        // Every registered loader contributed its defaults.
        $this->assertSame([], $config->servers->values);
        $this->assertSame(60, $config->ttl->get('apps'));
        $this->assertSame(['enabled' => true, 'channels' => ['email']], $config->notificationRules->defaults);
        $this->assertSame('consecutive_failures', $config->thresholds->alarmRules['app_unreachable']['strategy']);
        $this->assertSame(30, $config->dataRetention->metrics['raw_retention_days']);
        $this->assertSame(60, $config->intervals->eventMinimumMinutes['_default']);
        $this->assertEquals(ProbesConfig::default(), $config->probes);
        $this->assertSame([], $config->menu->overrides);
        $this->assertEquals(ChartsConfig::empty(), $config->charts);
        $this->assertInstanceOf(AlarmRateLimitsConfig::class, $config->alarmRateLimits);
    }

    public function testLoadNotificationRulesConfigNormalizesValidRules(): void
    {
        $rules = $this->loadNotificationRulesJson(<<<'JSON'
            {
              "defaults": {"enabled": true, "channels": [" Email ", "email"]},
              "event_types": {"fatal_errors_occurring": {"enabled": false, "channels": []}},
              "application_overrides": {"app": {"fatal_errors_occurring": {"enabled": true}}}
            }
            JSON);

        $this->assertSame(['enabled' => true, 'channels' => ['email']], $rules->defaults);
        $this->assertSame(['enabled' => false, 'channels' => []], $rules->eventTypes['fatal_errors_occurring']);
        $this->assertSame(['enabled' => true], $rules->applicationOverrides['app']['fatal_errors_occurring']);
    }

    public function testLoadNotificationRulesConfigFallsBackForUnreadablePath(): void
    {
        $configPath = $this->createTempDirectory();
        mkdir($configPath . '/notification_rules.json');
        $this->expectNotificationRulesFallback();

        try {
            $rules = $this->notificationRulesLoader->load($configPath);
        } finally {
            $this->cleanupTempFiles([$configPath]);
        }

        $this->assertDefaultNotificationRules($rules);
    }

    public function testLoadNotificationRulesConfigFallsBackForMalformedJson(): void
    {
        $this->expectNotificationRulesFallback();

        $this->assertDefaultNotificationRules($this->loadNotificationRulesJson('{'));
    }

    public function testLoadNotificationRulesConfigFallsBackForNonObjectJson(): void
    {
        $this->expectNotificationRulesFallback();

        $this->assertDefaultNotificationRules($this->loadNotificationRulesJson('[]'));
    }

    /** @param array<string,mixed> $rules */
    #[DataProvider('invalidNotificationRulesProvider')]
    public function testLoadNotificationRulesConfigFallsBackForInvalidSchema(array $rules): void
    {
        $this->expectNotificationRulesFallback();

        $this->assertDefaultNotificationRules($this->loadNotificationRulesJson(json_encode($rules, JSON_THROW_ON_ERROR)));
    }

    /** @return iterable<string,array{array<string,mixed>}> */
    public static function invalidNotificationRulesProvider(): iterable
    {
        yield 'unknown root key' => [['unknown' => true]];

        yield 'null section' => [['event_types' => null]];

        yield 'list section' => [['event_types' => []]];

        yield 'null rule' => [['event_types' => ['fatal' => null]]];

        yield 'unknown rule key' => [['defaults' => ['unknown' => true]]];

        yield 'non boolean enabled' => [['defaults' => ['enabled' => 1]]];

        yield 'null enabled' => [['defaults' => ['enabled' => null]]];

        yield 'channels object' => [['defaults' => ['channels' => ['email' => true]]]];

        yield 'null channels' => [['defaults' => ['channels' => null]]];

        yield 'non string channel' => [['defaults' => ['channels' => [1]]]];

        yield 'empty channel' => [['defaults' => ['channels' => [' ']]]];

        yield 'unsupported channel' => [['defaults' => ['channels' => ['webhook']]]];

        yield 'mixed unsupported channel' => [['defaults' => ['channels' => ['email', 'sms']]]];
    }

    public function testLoadTtlConfigAddsMissingCacheTtls(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'mata-ttl-');
        self::assertNotFalse($path);
        file_put_contents($path, json_encode(['apps' => 120], JSON_THROW_ON_ERROR));

        try {
            $ttl = $this->ttlLoader->loadTtlConfig($path);
        } finally {
            unlink($path);
        }

        $this->assertSame(120, $ttl->get('apps'));
        $this->assertSame(86400, $ttl->get('serverInfo'));
        $this->assertSame(86400, $ttl->get('audit'));
    }

    public function testLoadMenuConfigKeepsValidBooleanOverrides(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'mata-menu-');
        self::assertNotFalse($path);
        file_put_contents($path, json_encode(['metrics' => true, 'databases' => false], JSON_THROW_ON_ERROR));

        try {
            $menu = $this->menuLoader->loadMenuConfig($path);
        } finally {
            unlink($path);
        }

        $this->assertSame(['metrics' => true, 'databases' => false], $menu->overrides);
    }

    public function testLoadMenuConfigReturnsNoOverridesWhenFileMissing(): void
    {
        $this->useEventRecorderMock();
        $this->eventRecorder->expects($this->never())->method('recordEvent');

        $menu = $this->menuLoader->loadMenuConfig(sys_get_temp_dir() . '/mata-menu-does-not-exist.json');

        $this->assertSame([], $menu->overrides, 'a missing optional menu.json keeps built-in defaults silently');
    }

    public function testLoadMenuConfigWarnsAndDropsUnknownMenuKey(): void
    {
        $this->useEventRecorderMock();
        $this->eventRecorder
            ->expects($this->once())
            ->method('recordEvent')
            ->willReturn(new EventRecordResult(1, null, null, null, null, null));

        $menu = $this->loadMenuJson(json_encode(['events' => false, 'unknown' => true], JSON_THROW_ON_ERROR));

        $this->assertSame(['events' => false], $menu, 'valid entries survive while the unknown key is dropped');
    }

    public function testLoadMenuConfigWarnsAndDropsNonBooleanValue(): void
    {
        $this->useEventRecorderMock();
        $this->eventRecorder
            ->expects($this->once())
            ->method('recordEvent')
            ->willReturn(new EventRecordResult(1, null, null, null, null, null));

        $menu = $this->loadMenuJson(json_encode(['alarms' => 'yes', 'settings' => true], JSON_THROW_ON_ERROR));

        $this->assertSame(['settings' => true], $menu);
    }

    public function testLoadMenuConfigFallsBackForMalformedJson(): void
    {
        $this->useEventRecorderMock();
        $this->eventRecorder
            ->expects($this->once())
            ->method('recordEvent')
            ->willReturn(new EventRecordResult(1, null, null, null, null, null));

        $this->assertSame([], $this->loadMenuJson('{'));
    }

    // ==================== HTTPS Validation Tests ====================

    public function testLoadServersConfigExcludesDisabledServers(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'mata-servers-');
        self::assertNotFalse($path);
        file_put_contents($path, json_encode([
            'disabled-server' => ['disabled' => true],
            'enabled-server' => [
                'title' => 'Enabled Server',
                'api_url' => 'https://enabled.example.test/api',
                'api_key' => 'key123',
            ],
        ], JSON_THROW_ON_ERROR));

        try {
            $result = $this->loader->loadServersConfig($path);
        } finally {
            unlink($path);
        }

        $this->assertSame(['enabled-server'], array_keys($result->values));
    }

    public function testLoadServersConfigAllowsAllServersToBeDisabled(): void
    {
        $this->useEventRecorderMock();

        $this->eventRecorder->expects($this->never())->method('recordEvent');

        $path = tempnam(sys_get_temp_dir(), 'mata-servers-');
        self::assertNotFalse($path);
        file_put_contents($path, json_encode([
            'disabled-server' => ['disabled' => true],
        ], JSON_THROW_ON_ERROR));

        try {
            $result = $this->loader->loadServersConfig($path);
        } finally {
            unlink($path);
        }

        $this->assertSame([], $result->values);
    }

    public function testValidateServersConfigRejectsNonBooleanDisabledFlag(): void
    {
        $result = $this->loader->validateServersConfig([
            'server' => [
                'title' => 'Server',
                'api_url' => 'https://api.example.test',
                'api_key' => 'key123',
                'disabled' => 'true',
            ],
        ]);

        $this->assertFalse($result['is_valid']);
        $this->assertSame("Server 'server' disabled must be a boolean", $result['errors'][0]);
    }

    public function testLoadServersConfigExcludesOnlyServerWithNonBooleanDisabledFlag(): void
    {
        $this->useEventRecorderMock();
        $this->eventRecorder
            ->expects($this->once())
            ->method('recordEvent')
            ->with(
                'servers_config_validation_error',
                "Server 'invalid-server' disabled must be a boolean",
                $this->anything(),
                'warning',
            )
            ->willReturn(new EventRecordResult(1, null, null, null, null, null));

        $path = tempnam(sys_get_temp_dir(), 'mata-servers-');
        self::assertNotFalse($path);
        file_put_contents($path, json_encode([
            'invalid-server' => [
                'title' => 'Invalid Server',
                'api_url' => 'https://invalid.example.test',
                'api_key' => 'key123',
                'disabled' => 'true',
            ],
            'valid-server' => [
                'title' => 'Valid Server',
                'api_url' => 'https://valid.example.test',
                'api_key' => 'key456',
            ],
        ], JSON_THROW_ON_ERROR));

        try {
            $result = $this->loader->loadServersConfig($path);
        } finally {
            unlink($path);
        }

        $this->assertSame(['valid-server'], array_keys($result->values));
    }

    #[DataProvider('nonBooleanApplicationMonitoringFlagProvider')]
    public function testValidateServersConfigRejectsNonBooleanApplicationMonitoringFlags(string $setting): void
    {
        $result = $this->loader->validateServersConfig([
            'server' => [
                'title' => 'Server',
                'api_url' => 'https://api.example.test',
                'api_key' => 'key123',
                $setting => 'false',
            ],
        ]);

        $this->assertFalse($result['is_valid']);
        $this->assertSame("Server 'server' {$setting} must be a boolean", $result['errors'][0]);
    }

    /** @return iterable<string,array{string}> */
    public static function nonBooleanApplicationMonitoringFlagProvider(): iterable
    {
        yield 'error monitoring' => ['error_monitoring_enabled'];

        yield 'log-size monitoring' => ['logsize_monitoring_enabled'];
    }

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

    public function testValidateServersConfigAcceptsOptionalProdAlarmEmailEnvironment(): void
    {
        $result = $this->loader->validateServersConfig([
            'production-server' => [
                'title' => 'Production Server',
                'api_url' => 'https://api.example.test',
                'api_key' => 'key123',
                'env' => 'prod',
            ],
        ]);

        $this->assertTrue($result['is_valid']);
        $this->assertSame([], $result['errors']);
    }

    public function testValidateServersConfigAcceptsLegacyLiveAlarmEmailEnvironment(): void
    {
        $result = $this->loader->validateServersConfig([
            'production-server' => [
                'title' => 'Production Server',
                'api_url' => 'https://api.example.test',
                'api_key' => 'key123',
                'env' => 'live',
            ],
        ]);

        $this->assertTrue($result['is_valid']);
        $this->assertSame([], $result['errors']);
    }

    public function testValidateServersConfigEnvironmentErrorListsLive(): void
    {
        $result = $this->loader->validateServersConfig([
            'production-server' => [
                'title' => 'Production Server',
                'api_url' => 'https://api.example.test',
                'api_key' => 'key123',
                'env' => 'production',
            ],
        ]);

        $this->assertFalse($result['is_valid']);
        $this->assertSame(
            "Server 'production-server' env must be one of: prod, live, test, dev, staging",
            $result['errors'][0],
        );
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
        $this->useEventRecorderMock();

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
        $this->useEventRecorderMock();

        $this->eventRecorder
            ->expects($this->never())
            ->method('recordEvent');

        $result = $this->loader->validateServersConfig($config);

        $this->assertTrue($result['is_valid']);
        $this->assertEmpty($result['errors']);
    }

    public function testValidateServersConfigDoesNotWarnForDisabledHttpServer(): void
    {
        $this->useEventRecorderMock();
        $this->eventRecorder
            ->expects($this->never())
            ->method('recordEvent');

        $result = $this->loader->validateServersConfig([
            'disabled-server' => [
                'disabled' => true,
                'api_url' => 'http://localhost:3310/mata-node',
                'allow_http' => 'on',
            ],
        ]);

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
        $this->useEventRecorderMock();

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

    public function testDefaultThresholdsIncludeCronjobCountAndResourceUsageAlarms(): void
    {
        $method = new ReflectionMethod($this->thresholdsLoader, 'getDefaultConfig');
        $thresholds = $method->invoke($this->thresholdsLoader)->toArray();

        $this->assertSame(['good' => -1, 'alarm' => 0], $thresholds['server_data']['cronjobs_failed_count']);
        $this->assertArrayNotHasKey('orders_export_stale', $thresholds['alarms']);
        $this->assertArrayNotHasKey('server_metrics.ingestion_run_completed', $thresholds['alarms']);
        $this->assertSame([
            'strategy' => 'immediate',
        ], $thresholds['alarms']['server_apps_transport_unreachable']);
        $this->assertSame([
            'alarm_threshold_event_count' => 3,
            'alarm_threshold_window_minutes' => 30,
        ], $thresholds['alarms']['server_metrics.ingestion_run_empty']);
        $this->assertSame([
            'alarm_threshold_event_count' => 1,
            'alarm_threshold_window_minutes' => 60,
        ], $thresholds['alarms']['server_metrics.ingestion_stalled']);
        $this->assertSame([
            'alarm_threshold_event_count' => 2,
            'alarm_threshold_window_minutes' => 30,
        ], $thresholds['alarms']['cpu_usage_too_high']);
        $this->assertSame([
            'alarm_threshold_event_count' => 2,
            'alarm_threshold_window_minutes' => 30,
        ], $thresholds['alarms']['ram_usage_too_high']);
    }

    public function testInvalidAlarmRuleUsesTheDefaultRuleWhenAvailable(): void
    {
        $this->useEventRecorderMock();
        $this->eventRecorder
            ->expects($this->exactly(3))
            ->method('recordEvent')
            ->willReturn(new EventRecordResult(1, null, null, null, null, null));
        $path = tempnam(sys_get_temp_dir(), 'mata-thresholds-');
        self::assertNotFalse($path);
        file_put_contents($path, json_encode(['alarms' => [
            'app_unreachable' => ['strategy' => 'unknown'],
            'server_apps_transport_unreachable' => ['strategy' => 'unknown'],
            'custom_alarm' => ['strategy' => 'unknown'],
        ]], JSON_THROW_ON_ERROR));

        try {
            $thresholds = $this->thresholdsLoader->loadThresholdsConfig($path)->toArray();
        } finally {
            unlink($path);
        }

        $this->assertSame([
            'strategy' => 'consecutive_failures',
            'required_count' => 2,
            'window_minutes' => 10,
        ], $thresholds['alarms']['app_unreachable']);
        $this->assertSame(['strategy' => 'immediate'], $thresholds['alarms']['server_apps_transport_unreachable']);
        $this->assertArrayNotHasKey('custom_alarm', $thresholds['alarms']);
    }

    public function testDisabledAlarmRulesStillRequireValidConfiguration(): void
    {
        $this->useEventRecorderMock();
        $this->eventRecorder
            ->expects($this->exactly(2))
            ->method('recordEvent')
            ->willReturn(new EventRecordResult(1, null, null, null, null, null));
        $path = tempnam(sys_get_temp_dir(), 'mata-thresholds-');
        self::assertNotFalse($path);
        file_put_contents($path, json_encode(['alarms' => [
            'app_unreachable' => [
                'enabled' => false,
                'strategy' => 'consecutive_failures',
                'required_count' => 'invalid',
                'window_minutes' => 10,
            ],
            'server_apps_transport_unreachable' => [
                'enabled' => false,
                'strategy' => 'unknown',
            ],
            'custom_disabled' => ['enabled' => false],
        ]], JSON_THROW_ON_ERROR));

        try {
            $thresholds = $this->thresholdsLoader->loadThresholdsConfig($path)->toArray();
        } finally {
            unlink($path);
        }

        $this->assertSame([
            'strategy' => 'consecutive_failures',
            'required_count' => 2,
            'window_minutes' => 10,
        ], $thresholds['alarms']['app_unreachable']);
        $this->assertSame(['strategy' => 'immediate'], $thresholds['alarms']['server_apps_transport_unreachable']);
        $this->assertSame(['enabled' => false], $thresholds['alarms']['custom_disabled']);
    }

    public function testIntervalDefaultsFillMissingConfiguredEventTypes(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'mata-intervals-');
        self::assertNotFalse($path);
        file_put_contents($path, json_encode([
            'events' => ['min_event_interval_minutes' => ['_default' => 15]],
            'alarms' => ['min_alarm_interval_minutes' => ['_default' => 30]],
        ], JSON_THROW_ON_ERROR));

        try {
            $config = $this->intervalsLoader->loadIntervalsConfig($path)->toArray();
        } finally {
            unlink($path);
        }

        $this->assertSame(15, $config['events']['min_event_interval_minutes']['_default']);
        $this->assertSame(0, $config['events']['min_event_interval_minutes']['server_apps_transport_unreachable']);
        $this->assertSame(30, $config['alarms']['min_alarm_interval_minutes']['_default']);
        $this->assertSame(60, $config['alarms']['min_alarm_interval_minutes']['server_apps_transport_unreachable']);
    }

    public function testDefaultFatalErrorEventIntervalIsConfigured(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'mata-intervals-');
        self::assertNotFalse($path);
        file_put_contents($path, '{"unused": true}');

        try {
            $config = $this->intervalsLoader->loadIntervalsConfig($path)->toArray();
        } finally {
            unlink($path);
        }

        $eventIntervals = $config['events']['min_event_interval_minutes'];
        $alarmIntervals = $config['alarms']['min_alarm_interval_minutes'];
        $this->assertSame(0, $eventIntervals['app_unreachable']);
        $this->assertSame(0, $eventIntervals['server_apps_transport_unreachable']);
        $this->assertSame(0, $eventIntervals['fatal_errors_occurring']);
        $this->assertArrayNotHasKey('server_metrics.ingestion_run_completed', $eventIntervals);
        $this->assertSame(5, $eventIntervals['server_metrics.ingestion_run_empty']);
        $this->assertSame(15, $eventIntervals['server_metrics.ingestion_stalled']);
        $this->assertSame(30, $eventIntervals['cpu_usage_too_high']);
        $this->assertSame(30, $eventIntervals['ram_usage_too_high']);
        $this->assertArrayNotHasKey('orders_export_stale', $eventIntervals);
        $this->assertSame(60, $alarmIntervals['server_apps_transport_unreachable']);
        $this->assertSame(30, $alarmIntervals['server_metrics.ingestion_run_empty']);
        $this->assertSame(60, $alarmIntervals['server_metrics.ingestion_stalled']);
        $this->assertArrayNotHasKey('server_metrics.ingestion_run_completed', $alarmIntervals);
        $this->assertSame(180, $alarmIntervals['cpu_usage_too_high']);
        $this->assertSame(180, $alarmIntervals['ram_usage_too_high']);
        $this->assertArrayNotHasKey('orders_export_stale', $alarmIntervals);
        $this->assertSame(60, $eventIntervals['_default']);
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

    public function testDataRetentionRejectsNegativeRetention(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('data_retention.metrics.raw_retention_days must be at least 0.');

        DataRetentionConfig::fromArray(['metrics' => ['raw_retention_days' => -1]]);
    }

    private function expectNotificationRulesFallback(): void
    {
        $this->useEventRecorderMock();
        $this->eventRecorder
            ->expects($this->once())
            ->method('recordEvent')
            ->willReturn(new EventRecordResult(1, null, null, null, null, null));
    }

    /** @param array<string,mixed> $rules */
    private function assertDefaultNotificationRules(NotificationRulesConfig $rules): void
    {
        $this->assertSame(['enabled' => true, 'channels' => ['email']], $rules->defaults);
        $this->assertSame([], $rules->eventTypes);
        $this->assertSame([], $rules->applicationOverrides);
    }

    private function loadNotificationRulesJson(string $json): NotificationRulesConfig
    {
        $configPath = $this->createTempDirectory();
        file_put_contents($configPath . '/notification_rules.json', $json);

        try {
            return $this->notificationRulesLoader->load($configPath);
        } finally {
            $this->cleanupTempFiles([$configPath]);
        }
    }

    /** @return array<string,bool> */
    private function loadMenuJson(string $json): array
    {
        $configPath = $this->createTempDirectory();
        file_put_contents($configPath . '/menu.json', $json);

        try {
            return $this->menuLoader->load($configPath)->overrides;
        } finally {
            $this->cleanupTempFiles([$configPath]);
        }
    }

    private function initializeLoaders(): void
    {
        $this->loader = new ServersConfigLoader(events: $this->eventManagerFor($this->eventRecorder), fallbackOnFailure: false);
        $this->ttlLoader = new TtlConfigLoader(events: $this->eventManagerFor($this->eventRecorder), fallbackOnFailure: true);
        $this->notificationRulesLoader = new NotificationRulesConfigLoader(
            events: $this->eventManagerFor($this->eventRecorder),
            fallbackOnFailure: true,
        );
        $this->thresholdsLoader = new ThresholdsConfigLoader(
            events: $this->eventManagerFor($this->eventRecorder),
            fallbackOnFailure: true,
        );
        $this->intervalsLoader = new IntervalsConfigLoader(events: $this->eventManagerFor($this->eventRecorder), fallbackOnFailure: true);
        $this->dataRetentionLoader = new DataRetentionConfigLoader(events: $this->eventManagerFor($this->eventRecorder), fallbackOnFailure: true);
        $this->menuLoader = new MenuConfigLoader(
            events: $this->eventManagerFor($this->eventRecorder),
            validKeys: MenuBuilder::MENU_KEYS,
            fallbackOnFailure: true,
        );
        $this->manager = new MonitoringConfigLoader(
            servers: $this->loader,
            ttl: $this->ttlLoader,
            dataRetention: $this->dataRetentionLoader,
            intervals: $this->intervalsLoader,
            probes: new ProbesConfigLoader(events: $this->eventManagerFor($this->eventRecorder), fallbackOnFailure: true),
            notificationRules: $this->notificationRulesLoader,
            thresholds: $this->thresholdsLoader,
            menu: $this->menuLoader,
        );
    }

    private function useEventRecorderMock(): void
    {
        $this->eventRecorder = $this->createMock(EventRecorderService::class);
        $this->initializeLoaders();
    }

    private function alarmRateLimits(): AlarmRateLimitsConfig
    {
        return new AlarmRateLimitsConfig(new AlarmRateLimit(1, 1, 1), [], []);
    }

    /** @param array<string,mixed> $config */
    private function loadDataRetentionConfig(array $config): array
    {
        $path = tempnam(sys_get_temp_dir(), 'mata-retention-');
        self::assertNotFalse($path);
        file_put_contents($path, json_encode($config, JSON_THROW_ON_ERROR));

        try {
            return $this->dataRetentionLoader->loadDataRetentionConfig($path)->toArray();
        } finally {
            unlink($path);
        }
    }
}
