<?php

declare(strict_types=1);

namespace Mata\Services\ServerMetrics\Dto;

/** Who produced a raw server metric sample, as `server_metric_collectors` records it. */
final readonly class CollectorIdentity
{
    public function __construct(
        public string $name,
        public ?string $version = null,
        public ?string $contact = null,
    ) {}
}
