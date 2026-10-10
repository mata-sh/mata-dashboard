<?php

declare(strict_types=1);

namespace Mata\Services\Alarm\Delivery\Dto;

/** How an alarm reads on every channel: description, metric, server color and environment. */
final readonly class AlarmPresentation
{
    public function __construct(
        public string $description,
        public ?AlarmMetric $metric = null,
        public ?string $serverColor = null,
        public ?AlarmEnvironment $environment = null,
    ) {}
}
