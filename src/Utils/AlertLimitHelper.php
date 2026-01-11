<?php

declare(strict_types=1);

namespace Mata\Utils;

use Mata\Services\Alert\Dto\AlertLimit;

/**
 * Helper utilities for working with {@see AlertLimit} windows.
 */
final class AlertLimitHelper
{
    private function __construct() {}

    /**
     * Calculate minimum spacing between alerts when only filesystem locks are available.
     *
     * Returns the largest spacing derived from per-minute/hour/day limits to
     * ensure none of the windows are exceeded.
     */
    public static function calculateSpacingSeconds(AlertLimit $limit): int
    {
        $minute = $limit->getPerMinute() > 0 ? (int) max(1, floor(60 / $limit->getPerMinute())) : 0;
        $hour = $limit->getPerHour() > 0 ? (int) max(1, floor(3600 / $limit->getPerHour())) : 0;
        $day = $limit->getPerDay() > 0 ? (int) max(1, floor(86400 / $limit->getPerDay())) : 0;

        $window = array_filter([$minute, $hour, $day], static fn (int $value): bool => $value > 0);

        return $window === [] ? 0 : max($window);
    }
}
