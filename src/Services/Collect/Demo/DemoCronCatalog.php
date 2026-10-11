<?php

declare(strict_types=1);

namespace Mata\Services\Collect\Demo;

use Mata\Services\Cron\Dto\CronJobState;
use Mata\Services\Cron\Dto\CronJobStatus;

/**
 * Demo cron health: every job on schedule, with the intervals of docker/cron/crontab.
 */
final class DemoCronCatalog
{
    /** Seconds between runs per job. */
    private const INTERVALS = [
        'alarms-dispatch' => 60,
        'alarms-evaluate-critical' => 60,
        'alarms-evaluate-non-critical' => 300,
        'libraries-audit' => 21600,
        'rate-limit-cleanup' => 3600,
        'retention-cleanup' => 86400,
        'servers-data-fetch' => 300,
        'servers-metrics-aggregate' => 900,
        'servers-metrics-collect' => 300,
    ];

    private const DEFAULT_INTERVAL = 300;

    /**
     * @param list<string> $jobs
     *
     * @return list<CronJobStatus>
     */
    public function statuses(array $jobs, int $now): array
    {
        return array_map(static function (string $job) use ($now): CronJobStatus {
            $interval = self::INTERVALS[$job] ?? self::DEFAULT_INTERVAL;

            // Last run on the job's most recent schedule slot, as cron would start it.
            return new CronJobStatus($job, CronJobState::Ok, $now - $now % $interval, $interval);
        }, $jobs);
    }
}
