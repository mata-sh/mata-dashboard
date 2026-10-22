<?php

declare(strict_types=1);

namespace Mata\Services\Status\Dto;

use JsonSerializable;
use Mata\Config\Monitoring\Loader\Dto\ConfigLoadProblem;
use Mata\Services\Cron\Dto\CronJobState;
use Mata\Services\Cron\Dto\CronJobStatus;
use Mata\Services\Status\StatusLevel;
use Psr\Log\LogLevel;

/**
 * Explicit export shape of `bin/mata status`. Only these fields and those of ServerStatus
 * leave the process: server config (and its api_key) is never copied in.
 */
final readonly class FleetStatus implements JsonSerializable
{
    /**
     * @param list<ServerStatus>      $servers
     * @param list<CronJobStatus>     $cron
     * @param list<ConfigLoadProblem> $configProblems of the servers and TTL config
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
            ...array_map(static fn (ServerStatus $server): StatusLevel => $server->level, $this->servers),
            ...array_map(self::cronLevel(...), $this->cron),
            ...array_map(self::problemLevel(...), $this->configProblems),
        ]);
    }

    public static function cronLevel(CronJobStatus $cron): StatusLevel
    {
        return match ($cron->state) {
            CronJobState::Overdue => StatusLevel::Error,
            CronJobState::Never => StatusLevel::Warning,
            default => StatusLevel::Ok,
        };
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
    public function jsonSerialize(): array
    {
        return [
            'status' => $this->level()->label(),
            'generated_at' => $this->generatedAt,
            'servers' => $this->servers,
            'cron' => array_map(static fn (CronJobStatus $cron): array => [
                'level' => self::cronLevel($cron)->label(),
                'job' => $cron->job,
                'state' => $cron->state->value,
                'last_run' => $cron->lastRun,
                'interval_seconds' => $cron->interval,
            ], $this->cron),
            'config_problems' => count($this->configProblems),
        ];
    }
}
