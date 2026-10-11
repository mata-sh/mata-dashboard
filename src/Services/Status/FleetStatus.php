<?php

declare(strict_types=1);

namespace Mata\Services\Status;

/**
 * Explicit export shape of `bin/mata status`. Only these fields leave the process: server
 * config (and its api_key) is never copied in.
 */
final readonly class FleetStatus
{
    /**
     * @param list<array{name:string,title:string,health:string,level:StatusLevel,checked_at:?int,age_seconds:?int,error:?string}> $servers
     * @param list<array{job:string,state:string,level:StatusLevel,last_run:?int,interval_seconds:?int}>                           $cron
     */
    public function __construct(
        public array $servers,
        public array $cron,
        public int $generatedAt,
    ) {}

    public function level(): StatusLevel
    {
        $worst = StatusLevel::Ok;
        foreach ([...$this->servers, ...$this->cron] as $row) {
            if ($row['level']->severity() > $worst->severity()) {
                $worst = $row['level'];
            }
        }

        return $worst;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $export = static fn (array $row): array => ['level' => $row['level']->label()] + $row;

        return [
            'status' => $this->level()->label(),
            'generated_at' => $this->generatedAt,
            'servers' => array_map($export, $this->servers),
            'cron' => array_map($export, $this->cron),
        ];
    }
}
