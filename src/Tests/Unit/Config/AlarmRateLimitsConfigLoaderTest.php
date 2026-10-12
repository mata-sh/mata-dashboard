<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Config;

use Mata\Config\Monitoring\Loader\AlarmRateLimitsConfigLoader;
use Mata\Tests\TestCase;

/**
 * @covers \Mata\Config\Monitoring\Loader\AlarmRateLimitsConfigLoader
 *
 * @internal
 */
final class AlarmRateLimitsConfigLoaderTest extends TestCase
{
    public function testLoadAppliesGlobalChannelAndServerFallbacks(): void
    {
        $configRoot = $this->writeLimitsJson(json_encode([
            'channels' => [
                'default' => ['per_minute' => 5, 'per_hour' => 10, 'per_day' => 15],
                'email' => ['per_minute' => 6],
            ],
            'overrides' => [
                'Server' => ['email' => ['per_day' => 20]],
            ],
        ], JSON_THROW_ON_ERROR));

        try {
            $config = (new AlarmRateLimitsConfigLoader())->load($configRoot)->section;
        } finally {
            $this->cleanupTempFiles([$configRoot]);
        }

        $this->assertSame(['per_minute' => 6, 'per_hour' => 10, 'per_day' => 15], $config->resolve('', 'email')->toArray());
        $this->assertSame(['per_minute' => 6, 'per_hour' => 10, 'per_day' => 20], $config->resolve('server', 'EMAIL')->toArray());
        $this->assertSame(['per_minute' => 5, 'per_hour' => 10, 'per_day' => 15], $config->resolve('', 'webhook')->toArray());
    }

    public function testMalformedFileWarnsAndFallsBackToDefaults(): void
    {
        $configRoot = $this->writeLimitsJson('{');

        try {
            $loaded = (new AlarmRateLimitsConfigLoader())->load($configRoot);
        } finally {
            $this->cleanupTempFiles([$configRoot]);
        }

        $this->assertSame(['limits_config_invalid'], array_map(static fn ($problem): string => $problem->type, $loaded->problems));
        $this->assertSame('warning', $loaded->problems[0]->level);
        $this->assertSame(['per_minute' => 20, 'per_hour' => 120, 'per_day' => 500], $loaded->section->resolve('', 'email')->toArray());
    }

    public function testMissingFileSilentlyFallsBackToDefaults(): void
    {
        $configRoot = $this->createTempDirectory();

        try {
            $loaded = (new AlarmRateLimitsConfigLoader())->load($configRoot);
        } finally {
            $this->cleanupTempFiles([$configRoot]);
        }

        $this->assertSame([], $loaded->problems);
        $config = $loaded->section;
        $this->assertSame(['per_minute' => 20, 'per_hour' => 120, 'per_day' => 500], $config->resolve('', 'email')->toArray());
    }

    /** Write limits.json into a fresh configuration root and return that root. */
    private function writeLimitsJson(string $json): string
    {
        $configRoot = $this->createTempDirectory();
        file_put_contents($configRoot . '/limits.json', $json);

        return $configRoot;
    }
}
