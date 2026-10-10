<?php

declare(strict_types=1);

namespace Mata\Services\Monitor\Dto;

final readonly class AppLibraryAuditMonitoringResult
{
    public function __construct(
        public int $appsSeen = 0,
        public int $appsChecked = 0,
        /** Apps without Composer, with `monitoring.events` or `monitoring.libraryaudit` disabled. */
        public int $appsSkipped = 0,
        /** Audits that were missing or failed; their stored state is left untouched. */
        public int $auditsUnavailable = 0,
        public int $unchangedAdvisories = 0,
        public int $eventsRecorded = 0,
        public int $eventsNotRecorded = 0,
        public int $failures = 0,
    ) {}

    /** Combine results from separate servers; every field is an additive counter. */
    public function mergedWith(self $other): self
    {
        return new self(
            appsSeen: $this->appsSeen + $other->appsSeen,
            appsChecked: $this->appsChecked + $other->appsChecked,
            appsSkipped: $this->appsSkipped + $other->appsSkipped,
            auditsUnavailable: $this->auditsUnavailable + $other->auditsUnavailable,
            unchangedAdvisories: $this->unchangedAdvisories + $other->unchangedAdvisories,
            eventsRecorded: $this->eventsRecorded + $other->eventsRecorded,
            eventsNotRecorded: $this->eventsNotRecorded + $other->eventsNotRecorded,
            failures: $this->failures + $other->failures,
        );
    }

    public function summaryLine(): string
    {
        return sprintf(
            'apps=%d checked=%d skipped=%d unavailable=%d unchanged=%d events=%d not_recorded=%d failures=%d',
            $this->appsSeen,
            $this->appsChecked,
            $this->appsSkipped,
            $this->auditsUnavailable,
            $this->unchangedAdvisories,
            $this->eventsRecorded,
            $this->eventsNotRecorded,
            $this->failures,
        );
    }
}
