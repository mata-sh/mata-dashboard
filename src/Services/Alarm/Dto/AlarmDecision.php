<?php

declare(strict_types=1);

namespace Mata\Services\Alarm\Dto;

final class AlarmDecision
{
    /**
     * @param array<int,string> $channels
     */
    public function __construct(
        public bool $allow,
        public array $channels,
        public int $priority = 100,
        public bool $enrich = false,
        public ?string $reason = null
    ) {}
}
