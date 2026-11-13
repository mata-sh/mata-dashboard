<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services\Advisory;

use DateTimeImmutable;
use Mata\Config\Monitoring\Section\Advisory\AdvisorySource;
use Mata\Config\Monitoring\Section\Advisory\AdvisorySourceType;
use Mata\Models\Advisory\AdvisorySourceState;
use Mata\Services\Advisory\AdvisorySourceHealth;
use Mata\Services\Audit\Severity;
use Mata\Tests\TestCase;

/**
 * A source nobody polls any more must not show as OK, and a weekly one not as stale.
 *
 * @covers \Mata\Services\Advisory\AdvisorySourceHealth
 *
 * @internal
 */
final class AdvisorySourceHealthTest extends TestCase
{
    public function testASourceIsStaleOnlyAfterItsIntervalPlusTheWatchdogThreshold(): void
    {
        $now = new DateTimeImmutable('2026-10-24 12:00:00 UTC');
        $polledThreeDaysAgo = $this->state('2026-10-21 12:00:00');

        $this->assertSame(AdvisorySourceHealth::Stale, AdvisorySourceHealth::of($this->source(60), $polledThreeDaysAgo, $now, 360));
        $this->assertSame(AdvisorySourceHealth::Ok, AdvisorySourceHealth::of($this->source(10080), $polledThreeDaysAgo, $now, 360));
        $this->assertSame(AdvisorySourceHealth::Ok, AdvisorySourceHealth::of($this->source(60), $this->state('2026-10-24 05:30:00'), $now, 360));
    }

    private function source(int $intervalMinutes): AdvisorySource
    {
        return new AdvisorySource('cert-bund', 'CERT-Bund', '', AdvisorySourceType::Feed, 'https://example.org/feed', true, $intervalMinutes, [], Severity::Low, true);
    }

    private function state(string $polledAt): AdvisorySourceState
    {
        return new AdvisorySourceState('cert-bund', '2026-10-01 00:00:00', $polledAt, $polledAt, 0, null, 3, null);
    }
}
