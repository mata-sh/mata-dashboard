<?php

declare(strict_types=1);

namespace Mata\Services\Alarm\Dto;

final readonly class ResolvedNotificationRule
{
    /** @param list<string> $channels */
    public function __construct(
        public bool $enabled,
        public array $channels,
        public ?string $reason = null,
    ) {}

    /** @return array{enabled:bool,channels:list<string>,reason:?string} */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'channels' => $this->channels,
            'reason' => $this->reason,
        ];
    }
}
