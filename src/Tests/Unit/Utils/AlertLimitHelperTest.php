<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Utils;

use Watcher\Services\Foundation\Dto\AlertLimit;
use Watcher\Tests\TestCase;
use Watcher\Utils\AlertLimitHelper;

/**
 * @covers \Watcher\Utils\AlertLimitHelper
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
