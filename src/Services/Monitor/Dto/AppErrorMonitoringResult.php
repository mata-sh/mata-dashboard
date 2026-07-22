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
            $appsSeen,
            0,
            0,
            0,
            $demoSkipped,
            0,
            0,
            0,
            0,
            0,
            $failures
        );
    }
}
