<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Config;

use Mata\Config\Monitoring\Loader\AlarmRateLimitsConfigLoader;
use Mata\Services\Event\Dto\EventRecordResult;
use Mata\Services\Event\EventRecorderService;
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
            $config = (new AlarmRateLimitsConfigLoader($this->eventManagerFor($this->createStub(EventRecorderService::class))))->load($configRoot);
        } finally {
            $this->cleanupTempFiles([$configRoot]);
        }

        $this->assertSame(['per_minute' => 6, 'per_hour' => 10, 'per_day' => 15], $config->resolve('', 'email')->toArray());
        $this->assertSame(['per_minute' => 6, 'per_hour' => 10, 'per_day' => 20], $config->resolve('server', 'EMAIL')->toArray());
        $this->assertSame(['per_minute' => 5, 'per_hour' => 10, 'per_day' => 15], $config->resolve('', 'webhook')->toArray());
    }

    public function testMalformedFileWarnsAndFallsBackToDefaults(): void
    {
        $eventRecorder = $this->createMock(EventRecorderService::class);
        $eventRecorder->expects($this->once())
            ->method('recordEvent')
            ->with('limits_config_invalid', $this->anything(), $this->anything(), 'warning')
            ->willReturn(new EventRecordResult(1, null, null, null, null, null));
        $configRoot = $this->writeLimitsJson('{');

        try {
            $config = (new AlarmRateLimitsConfigLoader($this->eventManagerFor($eventRecorder)))->load($configRoot);
        } finally {
            $this->cleanupTempFiles([$configRoot]);
        }

        $this->assertSame(['per_minute' => 20, 'per_hour' => 120, 'per_day' => 500], $config->resolve('', 'email')->toArray());
    }

    public function testMissingFileSilentlyFallsBackToDefaults(): void
    {
        $eventRecorder = $this->createMock(EventRecorderService::class);
        $eventRecorder->expects($this->never())->method('recordEvent');
        $configRoot = $this->createTempDirectory();

        try {
            $config = (new AlarmRateLimitsConfigLoader($this->eventManagerFor($eventRecorder)))->load($configRoot);
        } finally {
            $this->cleanupTempFiles([$configRoot]);
        }

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
