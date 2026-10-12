<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Config;

use InvalidArgumentException;
use Mata\Config\Monitoring\Loader\ConfigLoadProblem;
use Mata\Config\Monitoring\Loader\ConfigLoadResult;
use Mata\Config\Monitoring\Loader\DataRetentionConfigLoader;
use Mata\Config\Monitoring\Loader\IntervalsConfigLoader;
use Mata\Config\Monitoring\Loader\MenuConfigLoader;
use Mata\Config\Monitoring\Loader\MonitoringConfigLoadException;
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
use Mata\Config\Monitoring\Section\MenuConfig;
use Mata\Config\Monitoring\Section\NotificationRulesConfig;
use Mata\Config\Monitoring\Section\ProbesConfig;
use Mata\Config\Monitoring\Section\ServersConfig;
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

        $this->initializeLoaders();
    }

    public function testLoadAllConfigsUsesConfigurationRootForEveryRuntimeEnvironment(): void
    {
        $configPath = $this->createTempDirectory();
        file_put_contents($configPath . '/servers.json', json_encode([
            'server' => [
                'title' => 'Server',
                'api_url' => 'https://server.example.test/api',
            ],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($configPath . '/servers.secrets.json', '{"server": {"api_key": "key123"}}');
        file_put_contents($configPath . '/ttl.json', '{"apps": 1}');
        file_put_contents($configPath . '/thresholds.json', '{"server_data": {}}');
        file_put_contents($configPath . '/intervals.json', '{"events": {}}');
        file_put_contents($configPath . '/data_retention.json', '{"retention": {}}');
        file_put_contents($configPath . '/probes.json', '{"max_retries": 3}');

        try {
            $config = $this->manager->load($configPath, $this->alarmRateLimits())->section;
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
        $configPath = $this->createTempDirectory();
        $manager = new MonitoringConfigLoader(
            servers: new ServersConfigLoader(fallbackOnFailure: true),
            ttl: new TtlConfigLoader(fallbackOnFailure: true),
            dataRetention: new DataRetentionConfigLoader(fallbackOnFailure: true),
            intervals: new IntervalsConfigLoader(fallbackOnFailure: true),
            probes: new ProbesConfigLoader(fallbackOnFailure: true),
            notificationRules: new NotificationRulesConfigLoader(fallbackOnFailure: true),
            thresholds: new ThresholdsConfigLoader(fallbackOnFailure: true),
            menu: new MenuConfigLoader(MenuBuilder::MENU_KEYS),
        );

        try {
            $loaded = $manager->load($configPath, $this->alarmRateLimits());
        } finally {
            $this->cleanupTempFiles([$configPath]);
        }

        // Required files only; missing optional files (probes, notification rules, menu) are silent.
        $this->assertCount(5, $loaded->problems);
        $config = $loaded->section;

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
            JSON)->section;

        $this->assertSame(['enabled' => true, 'channels' => ['email']], $rules->defaults);
        $this->assertSame(['enabled' => false, 'channels' => []], $rules->eventTypes['fatal_errors_occurring']);
        $this->assertSame(['enabled' => true], $rules->applicationOverrides['app']['fatal_errors_occurring']);
    }

    public function testNotificationRulesAcceptWebhookChannelNamesAndListEveryReferencedChannel(): void
    {
        $rules = $this->loadNotificationRulesJson(<<<'JSON'
            {
              "defaults": {"enabled": true, "channels": ["email", "Telegram"]},
              "event_types": {"node_unreachable": {"channels": ["ops-pager"]}},
              "application_overrides": {"shop": {"fatal_errors_occurring": {"channels": ["telegram", "slack_ops"]}}}
            }
            JSON)->section;

        $this->assertSame(['email', 'telegram'], $rules->defaults['channels']);
        $this->assertSame(['email', 'telegram', 'ops-pager', 'slack_ops'], $rules->channels());
    }

    public function testLoadNotificationRulesConfigFallsBackForUnreadablePath(): void
    {
        $configPath = $this->createTempDirectory();
        mkdir($configPath . '/notification_rules.json');

        try {
            $loaded = $this->notificationRulesLoader->load($configPath);
        } finally {
            $this->cleanupTempFiles([$configPath]);
        }

        $this->assertDefaultNotificationRulesFallback($loaded);
    }

    public function testLoadNotificationRulesConfigFallsBackForMalformedJson(): void
    {
        $this->assertDefaultNotificationRulesFallback($this->loadNotificationRulesJson('{'));
    }

    public function testLoadNotificationRulesConfigFallsBackForNonObjectJson(): void
    {
        $this->assertDefaultNotificationRulesFallback($this->loadNotificationRulesJson('[]'));
    }

    /** @param array<string,mixed> $rules */
    #[DataProvider('invalidNotificationRulesProvider')]
    public function testLoadNotificationRulesConfigFallsBackForInvalidSchema(array $rules): void
    {
        $this->assertDefaultNotificationRulesFallback($this->loadNotificationRulesJson(json_encode($rules, JSON_THROW_ON_ERROR)));
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

        yield 'invalid channel name' => [['defaults' => ['channels' => ['tele gram']]]];

        yield 'mixed invalid channel name' => [['defaults' => ['channels' => ['email', '-sms']]]];
    }

    public function testLoadTtlConfigAddsMissingCacheTtls(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'mata-ttl-');
        self::assertNotFalse($path);
        file_put_contents($path, json_encode(['apps' => 120], JSON_THROW_ON_ERROR));

        try {
            $ttl = $this->ttlLoader->loadTtlConfig($path)->section;
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
            $menu = $this->menuLoader->loadMenuConfig($path)->section;
        } finally {
            unlink($path);
        }

        $this->assertSame(['metrics' => true, 'databases' => false], $menu->overrides);
    }

    public function testLoadMenuConfigReturnsNoOverridesWhenFileMissing(): void
    {
        $loaded = $this->menuLoader->loadMenuConfig(sys_get_temp_dir() . '/mata-menu-does-not-exist.json');

        $this->assertSame([], $loaded->problems);
        $this->assertSame([], $loaded->section->overrides, 'a missing optional menu.json keeps built-in defaults silently');
    }

    public function testLoadMenuConfigWarnsAndDropsUnknownMenuKey(): void
    {
        $loaded = $this->loadMenuJson(json_encode(['events' => false, 'unknown' => true], JSON_THROW_ON_ERROR));

        $this->assertCount(1, $loaded->problems);
        $this->assertSame(['events' => false], $loaded->section->overrides, 'valid entries survive while the unknown key is dropped');
    }

    public function testLoadMenuConfigWarnsAndDropsNonBooleanValue(): void
    {
        $loaded = $this->loadMenuJson(json_encode(['alarms' => 'yes', 'settings' => true], JSON_THROW_ON_ERROR));

        $this->assertCount(1, $loaded->problems);
        $this->assertSame(['settings' => true], $loaded->section->overrides);
    }

    public function testLoadMenuConfigFallsBackForMalformedJson(): void
    {
        $loaded = $this->loadMenuJson('{');

        $this->assertCount(1, $loaded->problems);
        $this->assertSame([], $loaded->section->overrides);
    }

    // ==================== HTTPS Validation Tests ====================

    public function testLoadServersConfigExcludesDisabledServers(): void
    {
        $result = $this->loadServersFiles([
            'disabled-server' => ['disabled' => true],
            'enabled-server' => [
                'title' => 'Enabled Server',
                'api_url' => 'https://enabled.example.test/api',
            ],
        ], ['enabled-server' => ['api_key' => 'key123']]);

        $this->assertSame(['enabled-server'], array_keys($result->section->values));
    }

    public function testLoadServersConfigAllowsAllServersToBeDisabled(): void
    {
        $result = $this->loadServersFiles(['disabled-server' => ['disabled' => true]]);

        $this->assertSame([], $result->problems);
        $this->assertSame([], $result->section->values);
    }

    #[DataProvider('unloadableServersFileProvider')]
    public function testLoadServersConfigReportsWhyTheFileCouldNotBeLoaded(?string $contents, string $cause): void
    {
        $messages = [];
        $directory = $this->createTempDirectory();
        $path = $directory . '/servers.json';
        if ($contents !== null) {
            file_put_contents($path, $contents);
        }

        try {
            $this->loader->loadServersConfig($path);
            $this->fail('Expected the servers config load to fail');
        } catch (MonitoringConfigLoadException $exception) {
            $this->assertStringContainsString($cause, $exception->getMessage());
            foreach ($exception->problems as $problem) {
                $messages[$problem->type] = $problem->message;
            }
        } finally {
            $this->cleanupTempFiles([$directory]);
        }

        $this->assertStringContainsString($cause, $messages['servers_config_not_found'] ?? '');
        $this->assertStringNotContainsString('file not found', $messages['servers_config_not_found'] ?? '');
    }

    /** @return array<string,array{?string,string}> */
    public static function unloadableServersFileProvider(): array
    {
        return [
            'missing' => [null, 'Cannot read JSON config at'],
            'malformed' => ['{"server": ', 'Invalid JSON config at'],
            'empty' => ['{}', 'is empty'],
        ];
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
        $result = $this->loadServersFiles([
            'invalid-server' => [
                'title' => 'Invalid Server',
                'api_url' => 'https://invalid.example.test',
                'disabled' => 'true',
            ],
            'valid-server' => [
                'title' => 'Valid Server',
                'api_url' => 'https://valid.example.test',
            ],
        ], [
            'invalid-server' => ['api_key' => 'key123'],
            'valid-server' => ['api_key' => 'key456'],
        ]);

        $this->assertEquals(
            [new ConfigLoadProblem('servers_config_validation_error', "Server 'invalid-server' disabled must be a boolean")],
            $result->problems,
        );
        $this->assertSame(['valid-server'], array_keys($result->section->values));
    }

    // ==================== servers.secrets.json ====================

    public function testLoadServersConfigMergesApiKeyFromSecretsFile(): void
    {
        $result = $this->loadServersFiles(
            ['web' => ['title' => 'Web', 'api_url' => 'https://web.example.test']],
            ['web' => ['api_key' => 'secret-key']],
        );

        $this->assertSame('secret-key', $result->section->values['web']['api_key'] ?? null);
    }

    public function testLoadServersConfigRejectsApiKeyLeftInServersJson(): void
    {
        $result = $this->loadServersFiles(
            [
                'inline' => ['title' => 'Inline', 'api_url' => 'https://inline.example.test', 'api_key' => 'leaked'],
                'clean' => ['title' => 'Clean', 'api_url' => 'https://clean.example.test'],
            ],
            ['inline' => ['api_key' => 'moved'], 'clean' => ['api_key' => 'key']],
        );

        $messages = self::messages($result->problems);
        $this->assertSame(['clean'], array_keys($result->section->values));
        $this->assertContains("Server 'inline' sets api_key in servers.json; move it to servers.secrets.json", $messages);
        $this->assertSame([], preg_grep('/does not define/', $messages), 'a rejected server is not an unknown one');
    }

    public function testLoadServersConfigNamesTheSecretsFileWhenAKeyIsMissing(): void
    {
        $result = $this->loadServersFiles(['web' => ['title' => 'Web', 'api_url' => 'https://web.example.test']]);

        $this->assertSame([], $result->section->values);
        $this->assertContains("Server 'web' has no api_key in servers.secrets.json", self::messages($result->problems));
    }

    public function testLoadServersConfigWarnsAboutKeyForUndefinedServer(): void
    {
        $result = $this->loadServersFiles(
            ['web' => ['title' => 'Web', 'api_url' => 'https://web.example.test']],
            ['web' => ['api_key' => 'key'], 'renamed' => ['api_key' => 'stale']],
        );

        $this->assertSame(['web'], array_keys($result->section->values));
        $this->assertContains("servers.secrets.json has a key for server 'renamed', which servers.json does not define", self::messages($result->problems));
    }

    public function testLoadServersConfigFailsWhenSecretsFileIsMalformed(): void
    {
        $messages = [];
        $directory = $this->createTempDirectory();
        file_put_contents($directory . '/servers.json', '{"web": {"title": "Web", "api_url": "https://web.example.test"}}');
        file_put_contents($directory . '/servers.secrets.json', '{"web": ');

        try {
            $this->loader->loadServersConfig($directory . '/servers.json');
            $this->fail('Expected the servers config load to fail');
        } catch (MonitoringConfigLoadException $exception) {
            $this->assertStringContainsString('servers.secrets.json', $exception->getMessage());
            $messages = self::messages($exception->problems);
        } finally {
            $this->cleanupTempFiles([$directory]);
        }

        $this->assertNotSame([], preg_grep('/^Could not load servers: Invalid JSON config at .*servers\.secrets\.json/', $messages));
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

        $result = $this->loader->validateServersConfig($config);

        $this->assertTrue($result['is_valid']);
        $this->assertEmpty($result['errors']);
        $this->assertCount(1, $result['warnings']);
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

        $result = $this->loader->validateServersConfig($config);

        $this->assertTrue($result['is_valid']);
        $this->assertEmpty($result['errors']);
        $this->assertSame([], $result['warnings']);
    }

    public function testValidateServersConfigDoesNotWarnForDisabledHttpServer(): void
    {
        $result = $this->loader->validateServersConfig([
            'disabled-server' => [
                'disabled' => true,
                'api_url' => 'http://localhost:3310/mata-node',
                'allow_http' => 'on',
            ],
        ]);

        $this->assertTrue($result['is_valid']);
        $this->assertEmpty($result['errors']);
        $this->assertSame([], $result['warnings']);
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
        $this->assertStringContainsString('has no api_key in servers.secrets.json', $result['errors'][0]);
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

            $this->assertTrue($result['is_valid']);
            $this->assertSame([], $result['warnings'], "Expected suppress_http_warning='{$variation}' to suppress warnings");
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
        $path = tempnam(sys_get_temp_dir(), 'mata-thresholds-');
        self::assertNotFalse($path);
        file_put_contents($path, json_encode(['alarms' => [
            'app_unreachable' => ['strategy' => 'unknown'],
            'server_apps_transport_unreachable' => ['strategy' => 'unknown'],
            'custom_alarm' => ['strategy' => 'unknown'],
        ]], JSON_THROW_ON_ERROR));

        try {
            $loaded = $this->thresholdsLoader->loadThresholdsConfig($path);
        } finally {
            unlink($path);
        }

        $this->assertCount(3, $loaded->problems);
        $thresholds = $loaded->section->toArray();
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
            $loaded = $this->thresholdsLoader->loadThresholdsConfig($path);
        } finally {
            unlink($path);
        }

        $this->assertCount(2, $loaded->problems);
        $thresholds = $loaded->section->toArray();
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
            $config = $this->intervalsLoader->loadIntervalsConfig($path)->section->toArray();
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
            $config = $this->intervalsLoader->loadIntervalsConfig($path)->section->toArray();
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

    /** @param ConfigLoadResult<NotificationRulesConfig> $loaded */
    private function assertDefaultNotificationRulesFallback(ConfigLoadResult $loaded): void
    {
        $this->assertCount(1, $loaded->problems);
        $rules = $loaded->section;
        $this->assertSame(['enabled' => true, 'channels' => ['email']], $rules->defaults);
        $this->assertSame([], $rules->eventTypes);
        $this->assertSame([], $rules->applicationOverrides);
    }

    /** @return ConfigLoadResult<NotificationRulesConfig> */
    private function loadNotificationRulesJson(string $json): ConfigLoadResult
    {
        $configPath = $this->createTempDirectory();
        file_put_contents($configPath . '/notification_rules.json', $json);

        try {
            return $this->notificationRulesLoader->load($configPath);
        } finally {
            $this->cleanupTempFiles([$configPath]);
        }
    }

    /** @return ConfigLoadResult<MenuConfig> */
    private function loadMenuJson(string $json): ConfigLoadResult
    {
        $configPath = $this->createTempDirectory();
        file_put_contents($configPath . '/menu.json', $json);

        try {
            return $this->menuLoader->load($configPath);
        } finally {
            $this->cleanupTempFiles([$configPath]);
        }
    }

    private function initializeLoaders(): void
    {
        $this->loader = new ServersConfigLoader(fallbackOnFailure: false);
        $this->ttlLoader = new TtlConfigLoader(fallbackOnFailure: true);
        $this->notificationRulesLoader = new NotificationRulesConfigLoader(
            fallbackOnFailure: true,
        );
        $this->thresholdsLoader = new ThresholdsConfigLoader(
            fallbackOnFailure: true,
        );
        $this->intervalsLoader = new IntervalsConfigLoader(fallbackOnFailure: true);
        $this->dataRetentionLoader = new DataRetentionConfigLoader(fallbackOnFailure: true);
        $this->menuLoader = new MenuConfigLoader(
            validKeys: MenuBuilder::MENU_KEYS,
            fallbackOnFailure: true,
        );
        $this->manager = new MonitoringConfigLoader(
            servers: $this->loader,
            ttl: $this->ttlLoader,
            dataRetention: $this->dataRetentionLoader,
            intervals: $this->intervalsLoader,
            probes: new ProbesConfigLoader(fallbackOnFailure: true),
            notificationRules: $this->notificationRulesLoader,
            thresholds: $this->thresholdsLoader,
            menu: $this->menuLoader,
        );
    }

    /**
     * Load servers.json, and servers.secrets.json when given, from a private directory: the
     * loader reads the secrets file next to servers.json, so a shared temp dir could leak one in.
     *
     * @param array<string,mixed>      $servers
     * @param null|array<string,mixed> $secrets
     *
     * @return ConfigLoadResult<ServersConfig>
     */
    private function loadServersFiles(array $servers, ?array $secrets = null): ConfigLoadResult
    {
        $directory = $this->createTempDirectory();
        file_put_contents($directory . '/servers.json', json_encode($servers, JSON_THROW_ON_ERROR));
        if ($secrets !== null) {
            file_put_contents($directory . '/servers.secrets.json', json_encode($secrets, JSON_THROW_ON_ERROR));
        }

        try {
            return $this->loader->loadServersConfig($directory . '/servers.json');
        } finally {
            $this->cleanupTempFiles([$directory]);
        }
    }

    /**
     * @param list<ConfigLoadProblem> $problems
     *
     * @return list<string>
     */
    private static function messages(array $problems): array
    {
        return array_map(static fn (ConfigLoadProblem $problem): string => $problem->message, $problems);
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
            return $this->dataRetentionLoader->loadDataRetentionConfig($path)->section->toArray();
        } finally {
            unlink($path);
        }
    }
}
