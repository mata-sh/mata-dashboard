<?php

declare(strict_types=1);

namespace Mata\Services\Alarm\Dto;

/** Presentation of an alarm for the alarm email: description, metric card, server color and environment badge. */
final readonly class AlarmPresentation
{
    public function __construct(
        public string $description,
        public ?AlarmMetric $metric = null,
        public ?string $serverColor = null,
        public ?AlarmEnvironment $environment = null,
    ) {}
}
