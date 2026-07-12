<?php

declare(strict_types=1);

namespace Mata\Models\ServerMetrics;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Plans completed UTC aggregation windows. Planning is independent of the
 * latest persisted aggregate so a rerun repairs late or missing data.
 */
final class AggregationWindowPlanner
{
    private readonly DateTimeZone $utc;

    public function __construct()
    {
        $this->utc = new DateTimeZone('UTC');
    }

    /**
     * @return list<DateTimeImmutable>
     */
    public function hourlyWindows(
        DateTimeImmutable $now,
        int $lagMinutes,
        int $catchUpHours
    ): array {
        $asOf = $this->utc($now)->sub(new DateInterval('PT' . max(0, $lagMinutes) . 'M'));
        $target = $asOf->setTime((int) $asOf->format('H'), 0, 0)->sub(new DateInterval('PT1H'));

        return $this->windows($target, max(1, $catchUpHours), new DateInterval('PT1H'));
    }

    /**
     * @return list<DateTimeImmutable>
     */
    public function dailyWindows(
        DateTimeImmutable $now,
        int $lagMinutes,
        int $catchUpDays
    ): array {
        $asOf = $this->utc($now)->sub(new DateInterval('PT' . max(0, $lagMinutes) . 'M'));
        $target = $asOf->setTime(0, 0, 0)->sub(new DateInterval('P1D'));

        return $this->windows($target, max(1, $catchUpDays), new DateInterval('P1D'));
    }

    /**
     * @return list<DateTimeImmutable>
     */
    private function windows(DateTimeImmutable $target, int $count, DateInterval $interval): array
    {
        $start = $target;
        for ($remaining = $count - 1; $remaining > 0; --$remaining) {
            $start = $start->sub($interval);
        }

        $windows = [];
        for ($window = $start; $window <= $target; $window = $window->add($interval)) {
            $windows[] = $window;
        }

        return $windows;
    }

    private function utc(DateTimeImmutable $dateTime): DateTimeImmutable
    {
        return $dateTime->setTimezone($this->utc);
    }
}
