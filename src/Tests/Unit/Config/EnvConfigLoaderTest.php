<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Config;

use InvalidArgumentException;
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

    protected function tearDown(): void
    {
        unset($_ENV[self::KEY]);
        putenv(self::KEY);
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
}
