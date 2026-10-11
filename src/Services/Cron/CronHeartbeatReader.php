<?php

declare(strict_types=1);

namespace Mata\Services\Cron;

use Mata\Services\Cron\Dto\CronJobState;
use Mata\Services\Cron\Dto\CronJobStatus;

/**
 * Tells whether each job in cron/ still runs, from its heartbeat file.
 *
 * The crontab lives outside the app, so the interval is inferred from the gaps
 * between recent runs.
 */
final class CronHeartbeatReader
{
    /** Bytes read from the end of each heartbeat file; the files are never rotated. */
    private const TAIL_BYTES = 8192;

    /** Jobs heartbeat several times per run; closer heartbeats belong to the same run. */
    private const SAME_RUN_SECONDS = 55;

    /** Gaps between runs needed before the interval counts as known. */
    private const MIN_GAPS = 2;

    /** Cron starts jobs on the minute; allow for that and a slow run. */
    private const OVERDUE_GRACE_SECONDS = 60;

    public function __construct(
        private readonly string $entryPointDir,
        private readonly string $heartbeatDir,
    ) {}

    /**
     * Job names of the entry points in cron/, sorted.
     *
     * @return list<string>
     */
    public function jobs(): array
    {
        $entryPoints = glob($this->entryPointDir . '/*.php') ?: [];
        sort($entryPoints);

        return array_map(CronJob::nameFromEntryPoint(...), $entryPoints);
    }

    /**
     * @return list<CronJobStatus>
     */
    public function read(int $now): array
    {
        return array_map(
            fn (string $job): CronJobStatus => $this->status($job, "{$this->heartbeatDir}/heartbeat-{$job}.jsonl", $now),
            $this->jobs()
        );
    }

    private function status(string $job, string $heartbeatFile, int $now): CronJobStatus
    {
        // The file's mtime is the last heartbeat; unlike "ts" it does not depend on PHP's timezone.
        $lastRun = is_readable($heartbeatFile) ? filemtime($heartbeatFile) : false;
        if ($lastRun === false) {
            return new CronJobStatus($job, CronJobState::Never);
        }

        $interval = $this->inferInterval($this->tailTimestamps($heartbeatFile));
        $state = match (true) {
            $interval === null => CronJobState::Unknown,
            $now - $lastRun > 2 * $interval + self::OVERDUE_GRACE_SECONDS => CronJobState::Overdue,
            default => CronJobState::Ok,
        };

        return new CronJobStatus($job, $state, $lastRun, $interval);
    }

    /**
     * @return list<int>
     */
    private function tailTimestamps(string $heartbeatFile): array
    {
        $handle = fopen($heartbeatFile, 'rb');
        if ($handle === false) {
            return [];
        }

        $size = (int) (fstat($handle)['size'] ?? 0);
        fseek($handle, max(0, $size - self::TAIL_BYTES));
        $tail = (string) stream_get_contents($handle);
        fclose($handle);

        // Entries are pretty-printed JSON; a regex skips the entry cut off at the start.
        preg_match_all('/"ts":\s*"([^"]+)"/', $tail, $matches);

        $timestamps = [];
        foreach ($matches[1] as $value) {
            $timestamp = strtotime($value);
            if ($timestamp !== false) {
                $timestamps[] = $timestamp;
            }
        }

        return $timestamps;
    }

    /**
     * Median gap between runs, rounded to whole minutes like cron schedules.
     *
     * @param list<int> $timestamps
     */
    private function inferInterval(array $timestamps): ?int
    {
        sort($timestamps);

        $gaps = [];
        for ($i = 1, $count = count($timestamps); $i < $count; ++$i) {
            $gap = $timestamps[$i] - $timestamps[$i - 1];
            if ($gap >= self::SAME_RUN_SECONDS) {
                $gaps[] = $gap;
            }
        }

        if (count($gaps) < self::MIN_GAPS) {
            return null;
        }

        sort($gaps);
        $median = $gaps[intdiv(count($gaps), 2)];

        return max(60, (int) round($median / 60) * 60);
    }
}
