<?php

declare(strict_types=1);

namespace Mata\Services\Cron;

use Mata\Services\Cron\Dto\ServerDataFetchResult;
use Mata\Services\Monitor\Dto\AppErrorMonitoringResult;

final class ServerDataFetchStats
{
    private int $configured = 0;
    private int $completed = 0;
    private int $failed = 0;
    private int $unreachable = 0;
    private AppErrorMonitoringResult $monitoring;

    public function __construct()
    {
        $this->monitoring = AppErrorMonitoringResult::empty();
    }

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
        $this->monitoring = $this->monitoring->mergedWith($result->errorMonitoring);
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
                $this->monitoring->appsSeen,
                $this->monitoring->appsChecked,
                $this->monitoring->errorMonitoringDisabled,
                $this->monitoring->logsizeMonitoringDisabled,
                $this->monitoring->eventsDisabled,
                $this->monitoring->demoSkipped,
            ),
            sprintf(
                'Events: fatal-recorded=%d logfile-recorded=%d oversized-logs=%d unchanged=%d not-recorded=%d failures=%d',
                $this->monitoring->fatalEventsRecorded,
                $this->monitoring->logfileEventsRecorded,
                $this->monitoring->oversizedLogsDetected,
                $this->monitoring->unchangedFatalCursors,
                $this->monitoring->eventsNotRecorded,
                $this->monitoring->failures,
            ),
        ];
    }
}
