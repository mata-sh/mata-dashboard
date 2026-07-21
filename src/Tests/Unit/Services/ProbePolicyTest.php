<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use Mata\Services\Monitor\Dto\ProbePolicy;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Mata\Services\Monitor\Dto\ProbePolicy
 *
 * @internal
 */
final class ProbePolicyTest extends TestCase
{
    public function testEmptyConfigYieldsHistoricalDefaults(): void
    {
        $policy = ProbePolicy::fromArray([]);
        $default = ProbePolicy::default();

        $this->assertSame($default->timeoutSeconds, $policy->timeoutSeconds);
        $this->assertSame($default->maxRetries, $policy->maxRetries);
        $this->assertSame($default->backoffBaseMs, $policy->backoffBaseMs);
        $this->assertSame($default->backoffMaxMs, $policy->backoffMaxMs);
    }

    public function testClampsOutOfRangeValues(): void
    {
        $policy = ProbePolicy::fromArray([
            'timeout_seconds' => 0,
            'max_retries' => -3,
            'backoff_base_ms' => 900,
            'backoff_max_ms' => 100,
        ]);

        $this->assertSame(1, $policy->timeoutSeconds, 'timeout floors at 1 second');
        $this->assertSame(0, $policy->maxRetries, 'retries floor at 0');
        $this->assertSame(900, $policy->backoffBaseMs);
        $this->assertSame(900, $policy->backoffMaxMs, 'max is raised to at least the base');
    }

    public function testRetryabilityFollowsTheFixedFailureSemantics(): void
    {
        $policy = ProbePolicy::default();

        // Transient failures the probe classifier can recover from on retry.
        $this->assertTrue($policy->isRetryable('dns'));
        $this->assertTrue($policy->isRetryable('connect'));
        $this->assertTrue($policy->isRetryable('tls'));
        $this->assertTrue($policy->isRetryable('timeout'));

        // Deterministic outcomes and absent/unknown kinds are never retried.
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
