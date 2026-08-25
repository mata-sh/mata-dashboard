<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use InvalidArgumentException;
use Mata\Config\Monitoring\Section\ProbesConfig;
use Mata\Config\Monitoring\Section\ThresholdsConfig;
use Mata\Services\Monitor\Dto\AppReachabilityPolicy;
use Mata\Services\Monitor\Dto\ProbePolicy;
use Mata\Tests\Helpers\MonitoringConfigFactory;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Mata\Services\Monitor\Dto\AppReachabilityPolicy
 *
 * @internal
 */
final class AppReachabilityPolicyTest extends TestCase
{
    public function testDefaultsUseTheProbePolicyAndTenMinuteUnreachableWindow(): void
    {
        $policy = AppReachabilityPolicy::fromMonitoringConfig(MonitoringConfigFactory::create());

        $this->assertEquals(ProbePolicy::fromConfig(ProbesConfig::default()), $policy->probe);
        $this->assertSame(600, $policy->unreachableWindowSeconds);
    }

    public function testCustomProbeAndUnreachableConfigAreResolvedTogether(): void
    {
        $policy = AppReachabilityPolicy::fromMonitoringConfig(MonitoringConfigFactory::create(
            probes: ProbesConfig::fromArray([
                'timeout_seconds' => 12,
                'max_retries' => 3,
                'backoff_base_ms' => 100,
                'backoff_max_ms' => 400,
            ]),
            thresholds: new ThresholdsConfig(
                serverData: [],
                appData: [],
                alarmRules: ['app_unreachable' => ['window_minutes' => 15]],
            ),
        ));

        $this->assertSame(12, $policy->probe->timeoutSeconds);
        $this->assertSame(3, $policy->probe->maxRetries);
        $this->assertSame(100, $policy->probe->backoffBaseMs);
        $this->assertSame(400, $policy->probe->backoffMaxMs);
        $this->assertSame(900, $policy->unreachableWindowSeconds);
    }

    public function testInvalidUnreachableWindowCannotEnterPolicy(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('thresholds.alarms.app_unreachable.window_minutes must be at least 1.');

        new ThresholdsConfig(serverData: [], appData: [], alarmRules: ['app_unreachable' => ['window_minutes' => 0]]);
    }
}
