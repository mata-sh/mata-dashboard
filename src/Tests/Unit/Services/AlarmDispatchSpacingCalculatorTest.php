<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use Mata\Services\Alarm\AlarmDispatchSpacingCalculator;
use Mata\Services\Alarm\Dto\AlarmLimit;
use Mata\Tests\TestCase;

/**
 * @covers \Mata\Services\Alarm\AlarmDispatchSpacingCalculator
 *
 * @internal
 */
final class AlarmDispatchSpacingCalculatorTest extends TestCase
{
    public function testCalculateSpacingSecondsUsesLargestWindow(): void
    {
        $limit = new AlarmLimit(perMinute: 4, perHour: 30, perDay: 200);

        $spacing = AlarmDispatchSpacingCalculator::calculateSpacingSeconds($limit);

        // per-minute = 60/4=15s, per-hour=3600/30=120s, per-day=86400/200=432s => largest 432
        $this->assertSame(432, $spacing);
    }

    public function testCalculateSpacingSecondsReturnsZeroWhenUnlimited(): void
    {
        $limit = new AlarmLimit(perMinute: 0, perHour: 0, perDay: 0);

        $this->assertSame(0, AlarmDispatchSpacingCalculator::calculateSpacingSeconds($limit));
    }
}
