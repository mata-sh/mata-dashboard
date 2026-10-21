<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Config;

use Dotenv\Exception\InvalidFileException;
use InvalidArgumentException;
use Mata\Config\ConfigSource;
use Mata\Config\Dto\NodeApiConfig;
use Mata\Config\EnvConfigException;
use Mata\Config\EnvConfigLoader;
use Mata\Tests\TestCase;

/**
 * @covers \Mata\Config\EnvConfigLoader
 *
 * @internal
 */
final class EnvConfigLoaderTest extends TestCase
{
    private const KEY = 'MATA_TEST_ENV_NORMALIZATION';

    /** @var list<string> */
    private const CONFIG_KEYS = [
        'ALARM_DECISION_DRIVER',
        'ALARM_DECISION_BATCH_LIMIT',
        'OPA_ALARM_BASE_URI',
        'OPA_ALARM_DECISION_PATH',
        'OPA_ALARM_TIMEOUT_SECONDS',
        'NODE_API_TIMEOUT_SECONDS',
        'DEMO_SEED',
        self::KEY,
        'DOMAIN',
        'DB_HOST',
        'DB_USERNAME',
        'DB_PASSWORD',
        'DB_DATABASE',
        'DB_PORT',
        'TRUSTED_PROXIES',
        'APP_LOG_PATH',
        'MONOLOG_LEVEL',
        'SYM_MAILER_DSN',
        'SYM_FROM_EMAIL',
        'DASHBOARD_SERVER_COLUMNS',
        'APP_NAME',
    ];

    private array $originalEnv;
    private array $originalServer;
    private string $configPath;

    /** @var array<string,array{bool,mixed,false|string}> */
    private array $originalConfigurationEnvironment = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalEnv = $_ENV;
        $this->originalServer = $_SERVER;
        $this->configPath = $this->createTempDirectory();

        foreach (self::CONFIG_KEYS as $key) {
            $this->originalConfigurationEnvironment[$key] = [
                array_key_exists($key, $_ENV),
                $_ENV[$key] ?? null,
                getenv($key),
            ];
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        }
        foreach (['DB_HOST', 'DB_USERNAME', 'DB_PASSWORD', 'DB_DATABASE', 'DB_PORT'] as $key) {
            $_ENV[$key] = $key === 'DB_PORT' ? '3306' : 'test';
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->originalConfigurationEnvironment as $key => [$exists, $value, $processValue]) {
            if ($exists) {
                $_ENV[$key] = $value;
            } else {
                unset($_ENV[$key]);
            }

            if ($processValue === false) {
                putenv($key);
            } else {
                putenv("{$key}={$processValue}");
            }
        }

        $_ENV = $this->originalEnv;
        $_SERVER = $this->originalServer;
        $this->cleanupTempFiles([$this->configPath]);

        parent::tearDown();
    }

    public function testFilePrecedenceTracksOnlyAcceptedEffectiveAssignments(): void
    {
        file_put_contents($this->configPath . '/first.env', "DOMAIN=first.test\nAPP_NAME=old\nAPP_NAME=final\n");
        file_put_contents($this->configPath . '/second.env', "DOMAIN=shadowed.test\nSYM_FROM_EMAIL=mail@test\n");
        file_put_contents($this->configPath . '/third.env', "SYM_FROM_EMAIL=shadowed@test\nSYM_MAILER_DSN=null://null\n");
        $loader = new EnvConfigLoader();
        $config = $loader->load(['first.env', 'second.env', 'third.env'], $this->configPath);

        self::assertSame('first.test', $config->environment->domain);
        self::assertSame('final', $config->general->appName);
        self::assertEquals([
            ConfigSource::file('first.env', 'DOMAIN'),
            ConfigSource::file('second.env', 'SYM_FROM_EMAIL'),
            ConfigSource::file('third.env', 'SYM_MAILER_DSN'),
        ], array_map($loader->sourceOf(...), ['DOMAIN', 'SYM_FROM_EMAIL', 'SYM_MAILER_DSN']));
        self::assertSame([
            'kind' => 'file', 'file' => 'first.env', 'variable' => 'DOMAIN',
        ], $loader->sourceOf('DOMAIN')->jsonSerialize());
    }

    public function testApplicationPrecedenceDiffersFromDotenvRepository(): void
    {
        $_ENV['DOMAIN'] = 'env.test';
        putenv('DOMAIN=process.test');
        putenv('APP_NAME=process-name');
        $_SERVER['SYM_FROM_EMAIL'] = 'server@test';
        $_SERVER['SYM_MAILER_DSN'] = 'server-dsn';
        putenv('SYM_MAILER_DSN=process-dsn');
        file_put_contents($this->configPath . '/config.env', "DOMAIN=file.test\nAPP_NAME=file-name\nSYM_FROM_EMAIL=file@test\nSYM_MAILER_DSN=file-dsn\n");
        $loader = new EnvConfigLoader();
        $config = $loader->load(['config.env'], $this->configPath);

        self::assertSame('env.test', $config->environment->domain);
        self::assertSame('file-name', $config->general->appName);
        self::assertNull($config->mail->fromEmail);
        self::assertSame('process-dsn', $config->mail->dsn);
        self::assertEquals([
            ConfigSource::process('DOMAIN'),
            ConfigSource::file('config.env', 'APP_NAME'),
            ConfigSource::missing('SYM_FROM_EMAIL'),
            ConfigSource::process('SYM_MAILER_DSN'),
        ], array_map($loader->sourceOf(...), ['DOMAIN', 'APP_NAME', 'SYM_FROM_EMAIL', 'SYM_MAILER_DSN']));
    }

    public function testNullAssignmentsClearOwnershipAndExposeProcessFallback(): void
    {
        file_put_contents($this->configPath . '/config.env', "DOMAIN=file.test\nDOMAIN\nAPP_NAME=file-name\nAPP_NAME\n");
        putenv('APP_NAME=process-name');
        $loader = new EnvConfigLoader();
        $config = $loader->load(['config.env'], $this->configPath);

        self::assertSame('', $config->environment->domain);
        self::assertSame('process-name', $config->general->appName);
        self::assertEquals([
            ConfigSource::missing('DOMAIN'),
            ConfigSource::process('APP_NAME'),
        ], array_map($loader->sourceOf(...), ['DOMAIN', 'APP_NAME']));
    }

    public function testSourcesRetainEmptyAndNonStringInputs(): void
    {
        $loader = new EnvConfigLoader();
        foreach (['', ['invalid'], null, false] as $value) {
            $_ENV[self::KEY] = $value;
            putenv(self::KEY . '=fallback');
            $loader->load([], $this->configPath);
            self::assertEquals(ConfigSource::process(self::KEY), $loader->sourceOf(self::KEY));
        }
        unset($_ENV[self::KEY]);
        putenv(self::KEY);
        self::assertEquals(ConfigSource::missing(self::KEY), $loader->sourceOf(self::KEY));

        file_put_contents($this->configPath . '/config.env', self::KEY . "=\n");
        $loader->load(['config.env'], $this->configPath);
        self::assertEquals(ConfigSource::file('config.env', self::KEY), $loader->sourceOf(self::KEY));
    }

    public function testInvalidNumericAndDemoInputsIdentifyTheirFile(): void
    {
        foreach ([
            'DASHBOARD_SERVER_COLUMNS' => 'env.DASHBOARD_SERVER_COLUMNS must be an integer.',
            'OPA_ALARM_TIMEOUT_SECONDS' => 'env.OPA_ALARM_TIMEOUT_SECONDS must be a decimal number.',
            'DEMO_SEED' => 'env.DEMO_SEED must be an integer.',
        ] as $variable => $message) {
            file_put_contents($this->configPath . '/config.env', "{$variable}=invalid\n");
            $loader = new EnvConfigLoader();

            try {
                $loader->load(['config.env'], $this->configPath);
                self::fail('Expected invalid configuration to fail');
            } catch (EnvConfigException $exception) {
                self::assertSame($message, $exception->getMessage());
                self::assertEquals(ConfigSource::file('config.env', $variable), $exception->source);
            }
            unset($_ENV[$variable], $_SERVER[$variable]);
        }
    }

    public function testInvalidProcessNumericInputRetainsItsSource(): void
    {
        $_ENV['NODE_API_TIMEOUT_SECONDS'] = ['invalid'];
        $loader = new EnvConfigLoader();

        try {
            $loader->load([], $this->configPath);
            self::fail('Expected invalid configuration to fail');
        } catch (EnvConfigException $exception) {
            self::assertSame('env.NODE_API_TIMEOUT_SECONDS must be an integer.', $exception->getMessage());
            self::assertEquals(ConfigSource::process('NODE_API_TIMEOUT_SECONDS'), $exception->source);
        }
    }

    public function testRequiredFailuresDistinguishMissingAndEmptyFileInputs(): void
    {
        unset($_ENV['DB_HOST']);
        $loader = new EnvConfigLoader();
        foreach (['missing', 'file'] as $kind) {
            if ($kind === 'file') {
                file_put_contents($this->configPath . '/config.env', "DB_HOST=\n");
            }

            try {
                $loader->load(['config.env'], $this->configPath);
                self::fail('Expected required configuration to fail');
            } catch (EnvConfigException $exception) {
                self::assertSame('Required environment variable DB_HOST is missing', $exception->getMessage());
                self::assertEquals($kind === 'file' ? ConfigSource::file('config.env', 'DB_HOST') : ConfigSource::missing('DB_HOST'), $exception->source);
            }
        }
    }

    public function testMalformedLaterFilesIdentifyCurrentFileAndResetOnNextLoad(): void
    {
        $loader = new EnvConfigLoader();
        file_put_contents($this->configPath . '/first.env', "DOMAIN=first.test\n");
        foreach (['second.env', 'third.env'] as $file) {
            file_put_contents($this->configPath . '/' . $file, "INVALID NAME=value\n");

            try {
                $loader->load(['first.env', 'second.env', 'third.env'], $this->configPath);
                self::fail('Expected malformed file to fail');
            } catch (EnvConfigException $exception) {
                self::assertStringContainsString('Failed to parse dotenv file', $exception->getMessage());
                self::assertInstanceOf(InvalidFileException::class, $exception->getPrevious());
                self::assertEquals(ConfigSource::file($file), $exception->source);
            }
            file_put_contents($this->configPath . '/' . $file, '');
        }
        $loader->load(['first.env'], $this->configPath);
        // Previous loads left DOMAIN in $_ENV: this load did not assign it.
        self::assertEquals(ConfigSource::process('DOMAIN'), $loader->sourceOf('DOMAIN'));
    }

    public function testValidationMetadataNamesOnlyDiagnosedVariables(): void
    {
        $_ENV['DB_HOST'] = '0';
        $_ENV['DB_PASSWORD'] = '0';
        $_ENV['APP_LOG_PATH'] = '';
        $_ENV['MONOLOG_LEVEL'] = '';
        $_ENV['TRUSTED_PROXIES'] = 'invalid';
        $loader = new EnvConfigLoader();
        $result = $loader->validateConfig($loader->load([], $this->configPath));
        $variables = array_column(array_merge($result['critical_errors'], $result['warnings']), 'variables', 'type');
        self::assertSame([
            'config.domain.missing' => ['DOMAIN'],
            'config.trusted_proxy.invalid' => ['TRUSTED_PROXIES'],
            'config.mail.dsn.missing' => ['SYM_MAILER_DSN'],
            'config.database.missing' => ['DB_HOST', 'DB_PASSWORD'],
            'config.logging.file.missing' => ['APP_LOG_PATH'],
            'config.logging.level.missing' => ['MONOLOG_LEVEL'],
            'config.mail.from_email.missing' => ['SYM_FROM_EMAIL'],
        ], $variables);
    }

    public function testGetEnvPreservesZeroAndEmptyStrings(): void
    {
        $loader = new EnvConfigLoader();
        $_ENV[self::KEY] = '0';
        self::assertSame('0', $this->invokeMethod($loader, 'getEnv', [self::KEY, 'fallback']));

        $_ENV[self::KEY] = '';
        self::assertSame('', $this->invokeMethod($loader, 'getEnv', [self::KEY, 'fallback']));
    }

    public function testGetEnvUsesDefaultForMissingAndNonStringValues(): void
    {
        $loader = new EnvConfigLoader();
        self::assertSame('fallback', $this->invokeMethod($loader, 'getEnv', [self::KEY, 'fallback']));

        $_ENV[self::KEY] = ['invalid'];
        putenv(self::KEY . '=process-value');
        self::assertSame('fallback', $this->invokeMethod($loader, 'getEnv', [self::KEY, 'fallback']));
    }

    public function testRequiredEnvRejectsMissingAndEmptyValues(): void
    {
        $loader = new EnvConfigLoader();
        foreach ([null, ''] as $value) {
            unset($_ENV[self::KEY]);
            if ($value !== null) {
                $_ENV[self::KEY] = $value;
            }

            try {
                $this->invokeMethod($loader, 'requireEnv', [self::KEY]);
                self::fail('Expected missing required value to fail');
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString(self::KEY, $e->getMessage());
            }
        }
    }

    public function testOptionalEmptyNormalizationIsPreserved(): void
    {
        self::assertNull($this->invokeMethod(new EnvConfigLoader(), 'normalizeOptionalEnvValue', ['  ']));
    }

    public function testAlarmDecisionConfigUsesDefaultsForEmptyDriver(): void
    {
        $_ENV['ALARM_DECISION_DRIVER'] = '';

        $config = $this->invokeMethod(new EnvConfigLoader(), 'loadAlarmDecisionConfig');

        self::assertSame('opa', $config->driver);
        self::assertSame(100, $config->batchLimit);
        self::assertSame('http://127.0.0.1:3320/', $config->opa->baseUri);
        self::assertSame('/v1/data/alarms/decision', $config->opa->decisionPath);
        self::assertSame(5.0, $config->opa->timeoutSeconds);
    }

    public function testAlarmDecisionConfigMapsValidEnvironment(): void
    {
        $_ENV['ALARM_DECISION_DRIVER'] = ' BuiltIn ';
        $_ENV['ALARM_DECISION_BATCH_LIMIT'] = '25';
        $_ENV['OPA_ALARM_BASE_URI'] = 'http://opa.test/';
        $_ENV['OPA_ALARM_DECISION_PATH'] = 'v1/data/decision';
        $_ENV['OPA_ALARM_TIMEOUT_SECONDS'] = '2.5';

        $config = $this->invokeMethod(new EnvConfigLoader(), 'loadAlarmDecisionConfig');

        self::assertSame('builtin', $config->driver);
        self::assertSame(25, $config->batchLimit);
        self::assertSame('http://opa.test/', $config->opa->baseUri);
        self::assertSame('/v1/data/decision', $config->opa->decisionPath);
        self::assertSame(2.5, $config->opa->timeoutSeconds);
    }

    public function testAlarmDecisionConfigRejectsOutOfRangeIntegerEnvironment(): void
    {
        $_ENV['ALARM_DECISION_BATCH_LIMIT'] = '0';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('env.ALARM_DECISION_BATCH_LIMIT must be at least 1.');

        $this->invokeMethod(new EnvConfigLoader(), 'loadAlarmDecisionConfig');
    }

    public function testNodeApiConfigUsesDefaultTimeout(): void
    {
        $config = $this->invokeMethod(new EnvConfigLoader(), 'loadNodeApiConfig');

        self::assertSame(NodeApiConfig::DEFAULT_TIMEOUT_SECONDS, $config->timeoutSeconds);
    }

    public function testNodeApiConfigMapsValidEnvironment(): void
    {
        $_ENV['NODE_API_TIMEOUT_SECONDS'] = '25';

        $config = $this->invokeMethod(new EnvConfigLoader(), 'loadNodeApiConfig');

        self::assertSame(25, $config->timeoutSeconds);
    }

    public function testNodeApiConfigRejectsOutOfRangeEnvironment(): void
    {
        $_ENV['NODE_API_TIMEOUT_SECONDS'] = '0';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('env.NODE_API_TIMEOUT_SECONDS must be at least 1.');

        $this->invokeMethod(new EnvConfigLoader(), 'loadNodeApiConfig');
    }

    public function testDemoConfigDefaultsToNoSeed(): void
    {
        $_ENV['DEMO_SEED'] = '';

        $config = $this->invokeMethod(new EnvConfigLoader(), 'loadDemoConfig');

        self::assertNull($config->seed);
    }

    public function testDemoConfigMapsValidEnvironment(): void
    {
        $_ENV['DEMO_SEED'] = '42';

        $config = $this->invokeMethod(new EnvConfigLoader(), 'loadDemoConfig');

        self::assertSame(42, $config->seed);
    }

    public function testDemoConfigRejectsNegativeSeed(): void
    {
        $_ENV['DEMO_SEED'] = '-1';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('env.DEMO_SEED must be at least 0.');

        $this->invokeMethod(new EnvConfigLoader(), 'loadDemoConfig');
    }
}
