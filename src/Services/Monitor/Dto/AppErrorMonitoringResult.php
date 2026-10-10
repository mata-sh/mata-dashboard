<?php

declare(strict_types=1);

namespace Mata\Services\Monitor\Dto;

final readonly class AppErrorMonitoringResult
{
    public function __construct(
        public int $appsSeen,
        public int $appsChecked,
        public int $errorMonitoringDisabled,
        public int $logsizeMonitoringDisabled,
        /** Apps skipped wholesale because `monitoring.events` is disabled. */
        public int $eventsDisabled,
        public int $demoSkipped,
        public int $unchangedFatalCursors,
        public int $fatalEventsRecorded,
        public int $oversizedLogsDetected,
        public int $logfileEventsRecorded,
        public int $eventsNotRecorded,
        public int $failures,
    ) {}

    public static function empty(
        int $appsSeen = 0,
        int $demoSkipped = 0,
        int $failures = 0
    ): self {
        return new self(
            appsSeen: $appsSeen,
            appsChecked: 0,
            errorMonitoringDisabled: 0,
            logsizeMonitoringDisabled: 0,
            eventsDisabled: 0,
            demoSkipped: $demoSkipped,
            unchangedFatalCursors: 0,
            fatalEventsRecorded: 0,
            oversizedLogsDetected: 0,
            logfileEventsRecorded: 0,
            eventsNotRecorded: 0,
            failures: $failures,
        );
    }

    /** Combine results from separate servers; every field is an additive counter. */
    public function mergedWith(self $other): self
    {
        return new self(
            appsSeen: $this->appsSeen + $other->appsSeen,
            appsChecked: $this->appsChecked + $other->appsChecked,
            errorMonitoringDisabled: $this->errorMonitoringDisabled + $other->errorMonitoringDisabled,
            logsizeMonitoringDisabled: $this->logsizeMonitoringDisabled + $other->logsizeMonitoringDisabled,
            eventsDisabled: $this->eventsDisabled + $other->eventsDisabled,
            demoSkipped: $this->demoSkipped + $other->demoSkipped,
            unchangedFatalCursors: $this->unchangedFatalCursors + $other->unchangedFatalCursors,
            fatalEventsRecorded: $this->fatalEventsRecorded + $other->fatalEventsRecorded,
            oversizedLogsDetected: $this->oversizedLogsDetected + $other->oversizedLogsDetected,
            logfileEventsRecorded: $this->logfileEventsRecorded + $other->logfileEventsRecorded,
            eventsNotRecorded: $this->eventsNotRecorded + $other->eventsNotRecorded,
            failures: $this->failures + $other->failures,
        );
    }
}
