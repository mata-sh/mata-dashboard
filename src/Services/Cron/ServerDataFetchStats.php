<?php

declare(strict_types=1);

namespace Mata\Services\Cron;

use Mata\Services\Cron\Dto\ServerDataFetchResult;

final class ServerDataFetchStats
{
    private int $configured = 0;
    private int $completed = 0;
    private int $failed = 0;
    private int $unreachable = 0;
    private int $appsSeen = 0;
    private int $appsChecked = 0;
    private int $oversizedLogsDetected = 0;
    private int $fatalEventsRecorded = 0;
    private int $logfileEventsRecorded = 0;
    private int $unchangedFatalCursors = 0;
    private int $errorMonitoringDisabled = 0;
    private int $logsizeMonitoringDisabled = 0;
    private int $eventsDisabled = 0;
    private int $demoSkipped = 0;
    private int $eventsNotRecorded = 0;
    private int $monitorFailures = 0;

    public function recordConfigured(): void
    {
        ++$this->configured;
    }

    public function recordResult(ServerDataFetchResult $result): void
    {
        if ($result->nodeUnreachable) {
            ++$this->unreachable;

            return;
        }

        ++$this->completed;
        $monitoring = $result->errorMonitoring;
        $this->appsSeen += $monitoring->appsSeen;
        $this->appsChecked += $monitoring->appsChecked;
        $this->oversizedLogsDetected += $monitoring->oversizedLogsDetected;
        $this->fatalEventsRecorded += $monitoring->fatalEventsRecorded;
        $this->logfileEventsRecorded += $monitoring->logfileEventsRecorded;
        $this->unchangedFatalCursors += $monitoring->unchangedFatalCursors;
        $this->errorMonitoringDisabled += $monitoring->errorMonitoringDisabled;
        $this->logsizeMonitoringDisabled += $monitoring->logsizeMonitoringDisabled;
        $this->eventsDisabled += $monitoring->eventsDisabled;
        $this->demoSkipped += $monitoring->demoSkipped;
        $this->eventsNotRecorded += $monitoring->eventsNotRecorded;
        $this->monitorFailures += $monitoring->failures;
    }

    public function recordFailure(): void
    {
        ++$this->failed;
    }

    /** @return list<string> */
    public function summaryLines(float $durationSeconds, string $memoryUsage): array
    {
        return [
            sprintf(
                'Finished in %.3fs (memory: %s) | servers: configured=%d completed=%d unreachable=%d failed=%d',
                $durationSeconds,
                $memoryUsage,
                $this->configured,
                $this->completed,
                $this->unreachable,
                $this->failed,
            ),
            sprintf(
                'App monitoring: seen=%d checked=%d error-disabled=%d logsize-disabled=%d events-disabled=%d demo-skipped=%d',
                $this->appsSeen,
                $this->appsChecked,
                $this->errorMonitoringDisabled,
                $this->logsizeMonitoringDisabled,
                $this->eventsDisabled,
                $this->demoSkipped,
            ),
            sprintf(
                'Events: fatal-recorded=%d logfile-recorded=%d oversized-logs=%d unchanged=%d not-recorded=%d failures=%d',
                $this->fatalEventsRecorded,
                $this->logfileEventsRecorded,
                $this->oversizedLogsDetected,
                $this->unchangedFatalCursors,
                $this->eventsNotRecorded,
                $this->monitorFailures,
            ),
        ];
    }
}
