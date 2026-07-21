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
        $this->assertSame(['dns' => true, 'connect' => true, 'tls' => true, 'timeout' => true], $policy->retryableKinds);
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

    public function testRetryableKindsAreParsedIntoASetAndFallBackWhenEmpty(): void
    {
        $custom = ProbePolicy::fromArray(['retryable_kinds' => ['dns', 'connect']]);
        $this->assertSame(['dns' => true, 'connect' => true], $custom->retryableKinds);
        $this->assertTrue($custom->isRetryable('dns'));
        $this->assertFalse($custom->isRetryable('tls'));
        $this->assertFalse($custom->isRetryable(null));

        $this->assertSame(ProbePolicy::default()->retryableKinds, ProbePolicy::fromArray(['retryable_kinds' => []])->retryableKinds);
    }

    public function testBackoffIsBoundedGrowsAndDisablesWhenMaxIsZero(): void
    {
        $policy = new ProbePolicy(5, 5, 500, 1500, ['timeout' => true]);
        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $micros = $policy->backoffMicros($attempt);
            $this->assertGreaterThanOrEqual(0, $micros);
            $this->assertLessThanOrEqual(1500 * 1000, $micros, 'never exceeds the configured ceiling');
        }

        $this->assertSame(0, (new ProbePolicy(5, 1, 0, 0, ['timeout' => true]))->backoffMicros(1));
    }
}
