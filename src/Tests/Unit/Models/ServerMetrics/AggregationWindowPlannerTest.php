<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Models\ServerMetrics;

use DateTimeImmutable;
use DateTimeZone;
use Mata\Models\ServerMetrics\AggregationWindowPlanner;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Mata\Models\ServerMetrics\AggregationWindowPlanner
 *
 * @internal
 */
final class AggregationWindowPlannerTest extends TestCase
{
    public function testHourlyWindowsUseCompletedUtcHours(): void
    {
        $windows = (new AggregationWindowPlanner())->hourlyWindows(
            new DateTimeImmutable('2026-07-12 00:15:00', new DateTimeZone('UTC')),
            5,
            3
        );

        self::assertSame(['2026-07-11T21:00:00+00:00', '2026-07-11T22:00:00+00:00', '2026-07-11T23:00:00+00:00'], array_map(
            static fn (DateTimeImmutable $window): string => $window->format(DateTimeImmutable::ATOM),
            $windows
        ));
    }

    public function testDailyWindowsArePlannedIndependentlyAfterLag(): void
    {
        $planner = new AggregationWindowPlanner();
        $now = new DateTimeImmutable('2026-07-12 00:15:00', new DateTimeZone('UTC'));

        self::assertSame(['2026-07-09', '2026-07-10'], array_map(
            static fn (DateTimeImmutable $window): string => $window->format('Y-m-d'),
            $planner->dailyWindows($now, 30, 2)
        ));
        self::assertSame(['2026-07-10', '2026-07-11'], array_map(
            static fn (DateTimeImmutable $window): string => $window->format('Y-m-d'),
            $planner->dailyWindows($now->modify('+20 minutes'), 30, 2)
        ));
    }
}
