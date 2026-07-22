<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Config;

use Mata\Config\Monitoring\AlarmLimitsConfigLoader;
use Mata\Tests\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \Mata\Config\Monitoring\AlarmLimitsConfigLoader
 *
 * @internal
 */
final class AlarmLimitsConfigLoaderTest extends TestCase
{
    public function testLoadAppliesGlobalChannelAndServerFallbacks(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'mata-limits-');
        self::assertNotFalse($file);
        file_put_contents($file, json_encode([
            'channels' => [
                'default' => ['per_minute' => 5, 'per_hour' => 10, 'per_day' => 15],
                'email' => ['per_minute' => 6],
            ],
            'overrides' => [
                'Server' => ['email' => ['per_day' => 20]],
            ],
        ], JSON_THROW_ON_ERROR));

        try {
            $config = (new AlarmLimitsConfigLoader())->load($file);
        } finally {
            unlink($file);
        }

        $this->assertSame(['per_minute' => 6, 'per_hour' => 10, 'per_day' => 15], $config->resolve('', 'email')->toArray());
        $this->assertSame(['per_minute' => 6, 'per_hour' => 10, 'per_day' => 20], $config->resolve('server', 'EMAIL')->toArray());
        $this->assertSame(['per_minute' => 5, 'per_hour' => 10, 'per_day' => 15], $config->resolve('', 'webhook')->toArray());
    }

    public function testMalformedFileWarnsAndFallsBackToDefaults(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');
        $file = tempnam(sys_get_temp_dir(), 'mata-limits-');
        self::assertNotFalse($file);
        file_put_contents($file, '{');

        try {
            $config = (new AlarmLimitsConfigLoader($logger))->load($file);
        } finally {
            unlink($file);
        }

        $this->assertSame(['per_minute' => 20, 'per_hour' => 120, 'per_day' => 500], $config->resolve('', 'email')->toArray());
    }
}
