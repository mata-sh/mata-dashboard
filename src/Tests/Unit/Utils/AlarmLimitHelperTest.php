<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Utils;

use Mata\Services\Alarm\Dto\AlarmLimit;
use Mata\Tests\TestCase;
use Mata\Utils\AlarmLimitHelper;

/**
 * @covers \Mata\Utils\AlarmLimitHelper
 *
 * @internal
 */
final class AlarmLimitHelperTest extends TestCase
{
    public function testCalculateSpacingSecondsUsesLargestWindow(): void
    {
        $limit = new AlarmLimit(perMinute: 4, perHour: 30, perDay: 200);

        $spacing = AlarmLimitHelper::calculateSpacingSeconds($limit);

        // per-minute = 60/4=15s, per-hour=3600/30=120s, per-day=86400/200=432s => largest 432
        $this->assertSame(432, $spacing);
    }

    public function testCalculateSpacingSecondsReturnsZeroWhenUnlimited(): void
    {
        $limit = new AlarmLimit(perMinute: 0, perHour: 0, perDay: 0);

        $this->assertSame(0, AlarmLimitHelper::calculateSpacingSeconds($limit));
    }
}
