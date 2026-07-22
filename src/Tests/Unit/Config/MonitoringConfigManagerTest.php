<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Config;

use Mata\Config\Monitoring\MonitoringConfigLoaderInterface;
use Mata\Config\Monitoring\MonitoringConfigLoadException;
use Mata\Config\Monitoring\MonitoringConfigManager;
use Mata\Tests\TestCase;

/**
 * @covers \Mata\Config\Monitoring\MonitoringConfigManager
 *
 * @internal
 */
final class MonitoringConfigManagerTest extends TestCase
{
    public function testUsesLoaderDefaultAfterExpectedFailure(): void
    {
        $manager = new MonitoringConfigManager([
            new FailingMonitoringConfigLoader('servers', [], true),
        ]);

        $config = $manager->loadAllConfigs('/unused');

        $this->assertSame([], $config['servers']);
    }

    public function testRethrowsExpectedFailureWhenFallbackIsDisabled(): void
    {
        $manager = new MonitoringConfigManager([
            new FailingMonitoringConfigLoader('servers', [], false),
        ]);

        $this->expectException(MonitoringConfigLoadException::class);
        $manager->loadAllConfigs('/unused');
    }

    public function testContinuesAfterEarlyFailureAndReturnsTheNormalShape(): void
    {
        $manager = new MonitoringConfigManager([
            new FailingMonitoringConfigLoader('servers', [], true),
            new FailingMonitoringConfigLoader('thresholds', ['alarms' => ['app_unreachable' => ['strategy' => 'consecutive_failures']]], true),
            new StaticMonitoringConfigLoader('ttl', ['apps' => 60]),
        ]);

        $config = $manager->loadAllConfigs('/unused', 'demo');

        $this->assertSame([], $config['servers']);
        $this->assertSame(['strategy' => 'consecutive_failures'], $config['thresholds']['alarms']['app_unreachable']);
        $this->assertSame(['apps' => 60], $config['ttl']);
        $this->assertSame('demo', $config['environment']);
        $this->assertSame([], $config['charts']);
    }
}

final class FailingMonitoringConfigLoader implements MonitoringConfigLoaderInterface
{
    /** @param array<string,mixed> $defaultConfig */
    public function __construct(
        private string $key,
        private array $defaultConfig,
        private bool $fallbackOnFailure,
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    public function load(string $configRoot): array
    {
        throw new MonitoringConfigLoadException('Expected test configuration failure.');
    }

    public function getDefaultConfig(): array
    {
        return $this->defaultConfig;
    }

    public function shouldFallback(): bool
    {
        return $this->fallbackOnFailure;
    }
}

final class StaticMonitoringConfigLoader implements MonitoringConfigLoaderInterface
{
    /** @param array<string,mixed> $config */
    public function __construct(private string $key, private array $config) {}

    public function key(): string
    {
        return $this->key;
    }

    public function load(string $configRoot): array
    {
        return $this->config;
    }

    public function getDefaultConfig(): array
    {
        return $this->config;
    }

    public function shouldFallback(): bool
    {
        return false;
    }
}
