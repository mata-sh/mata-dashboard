<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use Mata\Services\Monitor\Dto\AppErrorMonitoringResult;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Mata\Services\Monitor\Dto\AppErrorMonitoringResult
 *
 * @internal
 */
final class AppErrorMonitoringResultTest extends TestCase
{
    public function testMergeSumsEveryCounter(): void
    {
        $merged = $this->first()->mergedWith($this->second());

        // Distinct per-field values make a swapped or dropped field produce a wrong sum.
        $this->assertEquals(new AppErrorMonitoringResult(
            appsSeen: 101,
            appsChecked: 202,
            errorMonitoringDisabled: 303,
            logsizeMonitoringDisabled: 404,
            eventsDisabled: 505,
            demoSkipped: 606,
            unchangedFatalCursors: 707,
            fatalEventsRecorded: 808,
            oversizedLogsDetected: 909,
            logfileEventsRecorded: 1010,
            eventsNotRecorded: 1111,
            failures: 1212,
        ), $merged);
    }

    public function testMergeWithEmptyKeepsValues(): void
    {
        $this->assertEquals($this->first(), $this->first()->mergedWith(AppErrorMonitoringResult::empty()));
        $this->assertEquals($this->first(), AppErrorMonitoringResult::empty()->mergedWith($this->first()));
    }

    private function first(): AppErrorMonitoringResult
    {
        return new AppErrorMonitoringResult(
            appsSeen: 1,
            appsChecked: 2,
            errorMonitoringDisabled: 3,
            logsizeMonitoringDisabled: 4,
            eventsDisabled: 5,
            demoSkipped: 6,
            unchangedFatalCursors: 7,
            fatalEventsRecorded: 8,
            oversizedLogsDetected: 9,
            logfileEventsRecorded: 10,
            eventsNotRecorded: 11,
            failures: 12,
        );
    }

    private function second(): AppErrorMonitoringResult
    {
        return new AppErrorMonitoringResult(
            appsSeen: 100,
            appsChecked: 200,
            errorMonitoringDisabled: 300,
            logsizeMonitoringDisabled: 400,
            eventsDisabled: 500,
            demoSkipped: 600,
            unchangedFatalCursors: 700,
            fatalEventsRecorded: 800,
            oversizedLogsDetected: 900,
            logfileEventsRecorded: 1000,
            eventsNotRecorded: 1100,
            failures: 1200,
        );
    }
}
