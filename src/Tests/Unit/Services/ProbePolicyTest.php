<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use Mata\Config\Monitoring\Section\ProbesConfig;
use Mata\Services\Monitor\Dto\ProbePolicy;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Mata\Services\Monitor\Dto\ProbePolicy
 *
 * @internal
 */
final class ProbePolicyTest extends TestCase
{
    public function testDerivesRuntimeBehaviorFromConfiguredValues(): void
    {
        $config = ProbesConfig::fromArray([
            'timeout_seconds' => 12,
            'max_retries' => 3,
            'backoff_base_ms' => 100,
            'backoff_max_ms' => 400,
        ]);

        $policy = ProbePolicy::fromConfig($config);

        $this->assertSame($config->timeoutSeconds, $policy->timeoutSeconds);
        $this->assertSame($config->maxRetries, $policy->maxRetries);
        $this->assertSame($config->backoffBaseMs, $policy->backoffBaseMs);
        $this->assertSame($config->backoffMaxMs, $policy->backoffMaxMs);
    }

    public function testRetryabilityFollowsTheFixedFailureSemantics(): void
    {
        $policy = ProbePolicy::fromConfig(ProbesConfig::default());

        $this->assertTrue($policy->isRetryable('dns'));
        $this->assertTrue($policy->isRetryable('connect'));
        $this->assertTrue($policy->isRetryable('tls'));
        $this->assertTrue($policy->isRetryable('timeout'));
        $this->assertFalse($policy->isRetryable('http'));
        $this->assertFalse($policy->isRetryable('invalid_response'));
        $this->assertFalse($policy->isRetryable('unknown'));
        $this->assertFalse($policy->isRetryable(null));
    }

    public function testBackoffIsBoundedGrowsAndDisablesWhenMaxIsZero(): void
    {
        $policy = new ProbePolicy(5, 5, 500, 1500);
        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $micros = $policy->backoffMicros($attempt);
            $this->assertGreaterThanOrEqual(0, $micros);
            $this->assertLessThanOrEqual(1500 * 1000, $micros, 'never exceeds the configured ceiling');
        }

        $this->assertSame(0, (new ProbePolicy(5, 1, 0, 1500))->backoffMicros(1));
        $this->assertSame(0, (new ProbePolicy(5, 1, 500, 0))->backoffMicros(1));
    }
}
