<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use Mata\Services\Monitor\Dto\AppReachabilityPolicy;
use Mata\Services\Monitor\Dto\ProbePolicy;
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
        $policy = AppReachabilityPolicy::fromSettings([]);

        $this->assertEquals(ProbePolicy::default(), $policy->probe);
        $this->assertSame(600, $policy->unreachableWindowSeconds);
    }

    public function testCustomProbeAndUnreachableSettingsAreResolvedTogether(): void
    {
        $policy = AppReachabilityPolicy::fromSettings([
            'probes' => [
                'timeout_seconds' => 12,
                'max_retries' => 3,
                'backoff_base_ms' => 100,
                'backoff_max_ms' => 400,
            ],
            'thresholds' => [
                'alarms' => ['app_unreachable' => ['window_minutes' => 15]],
            ],
        ]);

        $this->assertSame(12, $policy->probe->timeoutSeconds);
        $this->assertSame(3, $policy->probe->maxRetries);
        $this->assertSame(100, $policy->probe->backoffBaseMs);
        $this->assertSame(400, $policy->probe->backoffMaxMs);
        $this->assertSame(900, $policy->unreachableWindowSeconds);
    }

    public function testUnreachableWindowIsClampedToOneMinute(): void
    {
        $policy = AppReachabilityPolicy::fromSettings([
            'thresholds' => ['alarms' => ['app_unreachable' => ['window_minutes' => 0]]],
        ]);

        $this->assertSame(60, $policy->unreachableWindowSeconds);
    }
}
