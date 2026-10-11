<?php

declare(strict_types=1);

namespace Mata\Services\Cron\Dto;

final readonly class CronJobStatus
{
    /**
     * @param null|int $lastRun  Unix timestamp of the last heartbeat
     * @param null|int $interval Inferred seconds between runs
     */
    public function __construct(
        public string $job,
        public CronJobState $state,
        public ?int $lastRun = null,
        public ?int $interval = null,
    ) {}
}
