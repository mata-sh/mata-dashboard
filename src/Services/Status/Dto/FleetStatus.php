<?php

declare(strict_types=1);

namespace Mata\Services\Status\Dto;

use Mata\Config\Monitoring\Loader\Dto\ConfigLoadProblem;
use Mata\Services\Status\StatusLevel;
use Psr\Log\LogLevel;

/**
 * Explicit export shape of `bin/mata status`. Only these fields leave the process: server
 * config (and its api_key) is never copied in.
 */
final readonly class FleetStatus
{
    /**
     * @param list<array{name:string,title:string,health:string,level:StatusLevel,checked_at:?int,age_seconds:?int,error:?string}> $servers
     * @param list<array{job:string,state:string,level:StatusLevel,last_run:?int,interval_seconds:?int}>                           $cron
     * @param list<ConfigLoadProblem>                                                                                              $configProblems of the servers and TTL config
     */
    public function __construct(
        public array $servers,
        public array $cron,
        public int $generatedAt,
        public array $configProblems = [],
    ) {}

    public function level(): StatusLevel
    {
        return StatusLevel::worst([
            ...array_column([...$this->servers, ...$this->cron], 'level'),
            ...array_map(self::problemLevel(...), $this->configProblems),
        ]);
    }

    /**
     * A rejected servers file leaves the fleet empty, so its health is unknown rather than ok;
     * a server that is down still reports error. Any other problem, such as a dropped entry, warns.
     */
    private static function problemLevel(ConfigLoadProblem $problem): StatusLevel
    {
        return $problem->level === LogLevel::CRITICAL ? StatusLevel::Unknown : StatusLevel::Warning;
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
            'config_problems' => count($this->configProblems),
        ];
    }
}
