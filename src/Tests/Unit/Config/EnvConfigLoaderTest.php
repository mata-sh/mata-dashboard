<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Config;

use InvalidArgumentException;
use Mata\Config\Dto\NodeApiConfig;
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
    ];

    /** @var array<string,array{bool,mixed,false|string}> */
    private array $originalConfigurationEnvironment = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::CONFIG_KEYS as $key) {
            $this->originalConfigurationEnvironment[$key] = [
                array_key_exists($key, $_ENV),
                $_ENV[$key] ?? null,
                getenv($key),
            ];
            unset($_ENV[$key]);
            putenv($key);
        }
    }

    protected function tearDown(): void
    {
        unset($_ENV[self::KEY]);
        putenv(self::KEY);

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

        parent::tearDown();
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
}
