<?php

declare(strict_types=1);

namespace Mata\Services\Status\Dto;

use JsonSerializable;
use Mata\Services\Monitor\Dto\NodeHealthState;
use Mata\Services\Status\StatusLevel;

/** One server in `bin/mata status`, from the health result cron cached for it. */
final readonly class ServerStatus implements JsonSerializable
{
    /**
     * @param null|int $checkedAt  when cron cached the result; null when there is none
     * @param null|int $ageSeconds seconds since then
     */
    public function __construct(
        public string $name,
        public string $title,
        public NodeHealthState $health,
        public StatusLevel $level,
        public ?int $checkedAt = null,
        public ?int $ageSeconds = null,
        public ?string $error = null,
    ) {}

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'level' => $this->level->label(),
            'name' => $this->name,
            'title' => $this->title,
            'health' => $this->health->value,
            'checked_at' => $this->checkedAt,
            'age_seconds' => $this->ageSeconds,
            'error' => $this->error,
        ];
    }
}
