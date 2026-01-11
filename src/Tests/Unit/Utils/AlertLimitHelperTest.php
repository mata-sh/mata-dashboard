<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Utils;

use Mata\Services\Alert\Dto\AlertLimit;
use Mata\Tests\TestCase;
use Mata\Utils\AlertLimitHelper;

/**
 * @covers \Mata\Utils\AlertLimitHelper
 *
 * @internal
 */
final class AlertLimitHelperTest extends TestCase
{
    public function testCalculateSpacingSecondsUsesLargestWindow(): void
    {
        $limit = new AlertLimit(perMinute: 4, perHour: 30, perDay: 200);

        $spacing = AlertLimitHelper::calculateSpacingSeconds($limit);

        // per-minute = 60/4=15s, per-hour=3600/30=120s, per-day=86400/200=432s => largest 432
        $this->assertSame(432, $spacing);
    }

    public function testCalculateSpacingSecondsReturnsZeroWhenUnlimited(): void
    {
        $limit = new AlertLimit(perMinute: 0, perHour: 0, perDay: 0);

        $this->assertSame(0, AlertLimitHelper::calculateSpacingSeconds($limit));
    }
}
