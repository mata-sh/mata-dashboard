<?php

declare(strict_types=1);

namespace Mata\Services\Alarm\Dto;

/** Contains the completed alarm decision processing result. */
final readonly class AlarmProcessingResult
{
    public function __construct(
        public AlarmEvaluationRecord $record,
        public int $enqueuedCount,
        public bool $enrichmentRequested,
        public bool $skipped,
    ) {}
}
