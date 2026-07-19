<?php

declare(strict_types=1);

namespace Mata\Services\Alarm\Dto;

/** A single metric shown in the alarm email metric card. */
final readonly class AlarmMetric
{
    public function __construct(
        public string $label,
        public string $value,
        public ?string $threshold = null,
    ) {}
}
