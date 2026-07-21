<?php

declare(strict_types=1);

namespace Mata\Services\Alarm\Dto;

/** A single metric shown in the alarm email metric card. */
final class AlarmMetric
{
    public function __construct(
        public readonly string $label,
        public readonly string $value,
        public readonly ?string $detail = null,
    ) {}

    /** Temporary read compatibility for integrations using the old presentation property. */
    public function __get(string $name): ?string
    {
        return $name === 'threshold' ? $this->detail : null;
    }
}
