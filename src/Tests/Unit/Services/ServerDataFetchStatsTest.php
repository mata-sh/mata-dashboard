<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use Mata\Services\Cron\Dto\ServerDataFetchResult;
use Mata\Services\Cron\ServerDataFetchStats;
use Mata\Services\Monitor\Dto\AppErrorMonitoringResult;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Mata\Services\Cron\ServerDataFetchStats
 *
 * @internal
 */
final class ServerDataFetchStatsTest extends TestCase
{
    public function testAggregatesWarmResultsAndFormatsGroupedSummaryLines(): void
    {
        $stats = new ServerDataFetchStats();
        $stats->recordConfigured();
        $stats->recordConfigured();
        $stats->recordResult(new ServerDataFetchResult(
            true,
            0,
            0,
            new AppErrorMonitoringResult(4, 3, 1, 0, 1, 0, 2, 1, 1, 1, 1, 0),
        ));
        $stats->recordResult(new ServerDataFetchResult(
            true,
            0,
            0,
            new AppErrorMonitoringResult(2, 2, 0, 1, 2, 1, 3, 2, 0, 0, 0, 1),
        ));
        $stats->recordFailure();

        $this->assertSame([
            'Finished in 1.250s (memory: 12 MB) | servers: configured=2 completed=2 unreachable=0 failed=1',
            'App monitoring: seen=6 checked=5 error-disabled=1 logsize-disabled=1 events-disabled=3 demo-skipped=1',
            'Events: fatal-recorded=3 logfile-recorded=1 oversized-logs=1 unchanged=5 not-recorded=1 failures=1',
        ], $stats->summaryLines(1.25, '12 MB'));
    }

    public function testCountsUnreachableNodesSeparatelyFromCompletedOnes(): void
    {
        $stats = new ServerDataFetchStats();
        $stats->recordConfigured();
        $stats->recordConfigured();
        $stats->recordResult(new ServerDataFetchResult(
            true,
            0,
            0,
            new AppErrorMonitoringResult(4, 3, 1, 0, 1, 0, 2, 1, 1, 1, 1, 0),
        ));
        $stats->recordResult(ServerDataFetchResult::nodeUnreachable());

        $lines = $stats->summaryLines(0.5, '8 MB');

        $this->assertSame(
            'Finished in 0.500s (memory: 8 MB) | servers: configured=2 completed=1 unreachable=1 failed=0',
            $lines[0],
        );
        // A skipped node contributes no app monitoring counters.
        $this->assertSame(
            'App monitoring: seen=4 checked=3 error-disabled=1 logsize-disabled=0 events-disabled=1 demo-skipped=0',
            $lines[1],
        );
    }
}
