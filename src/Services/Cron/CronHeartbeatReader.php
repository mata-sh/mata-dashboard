<?php

declare(strict_types=1);

namespace Mata\Services\Cron;

use Mata\Services\Cron\Dto\CronHeartbeatSnapshot;
use Mata\Services\Cron\Dto\CronJobState;
use Mata\Services\Cron\Dto\CronJobStatus;
use Mata\Utils\PathResolver;

/**
 * Tells whether each job in cron/ still runs, from its heartbeat file.
 *
 * The crontab lives outside the app, so the interval is inferred from the gaps
 * between recent runs.
 */
final class CronHeartbeatReader
{
    /** Bytes read from the end of each heartbeat file. */
    private const TAIL_BYTES = 8192;

    /** Jobs heartbeat several times per run; closer heartbeats belong to the same run. */
    private const SAME_RUN_SECONDS = 55;

    /** Gaps between runs needed before the interval counts as known. */
    private const MIN_GAPS = 2;

    /** Cron starts jobs on the minute; allow for that and a slow run. */
    private const OVERDUE_GRACE_SECONDS = 60;

    /** Every job runs at least daily; one that has not run within a day after cron started is missing. */
    private const PENDING_SECONDS = 86_400 + 3_600;

    public function __construct(
        private readonly string $entryPointDir,
        private readonly string $heartbeatDir,
    ) {}

    public static function fromPaths(PathResolver $paths): self
    {
        return new self($paths->appPath . '/cron', $paths->cronLogsPath);
    }

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
        $snapshots = [];
        foreach ($this->jobs() as $job) {
            $snapshots[$job] = $this->snapshot(CronJob::heartbeatFile($this->heartbeatDir, $job));
        }
        $cronStarted = $this->cronStarted($snapshots);

        return array_map(
            fn (string $job): CronJobStatus => $this->status($job, $snapshots[$job], $now, $cronStarted),
            array_keys($snapshots)
        );
    }

    private function status(string $job, CronHeartbeatSnapshot $snapshot, int $now, ?int $cronStarted): CronJobStatus
    {
        $lastRun = $snapshot->lastModified;
        if ($lastRun === null) {
            $pending = $cronStarted !== null && $now - $cronStarted < self::PENDING_SECONDS;

            return new CronJobStatus($job, $pending ? CronJobState::Pending : CronJobState::Never);
        }

        $interval = $this->inferInterval($this->timestamps($snapshot->tail));
        $state = match (true) {
            $interval === null => CronJobState::Unknown,
            $now - $lastRun > 2 * $interval + self::OVERDUE_GRACE_SECONDS => CronJobState::Overdue,
            default => CronJobState::Ok,
        };

        return new CronJobStatus($job, $state, $lastRun, $interval);
    }

    /**
     * First heartbeat of any job, from the start of each file; null when no job has run.
     *
     * @param array<string,CronHeartbeatSnapshot> $snapshots
     */
    private function cronStarted(array $snapshots): ?int
    {
        $first = null;
        foreach ($snapshots as $snapshot) {
            $timestamp = $this->timestamps($snapshot->head)[0] ?? null;
            if ($timestamp !== null) {
                $first = $first === null ? $timestamp : min($first, $timestamp);
            }
        }

        return $first;
    }

    /** Head, tail and mtime under one shared lock, so a compaction is never seen half-written. */
    private function snapshot(string $file): CronHeartbeatSnapshot
    {
        $handle = is_readable($file) ? fopen($file, 'rb') : false;
        if ($handle === false) {
            return new CronHeartbeatSnapshot();
        }

        try {
            if (!flock($handle, LOCK_SH)) {
                return new CronHeartbeatSnapshot();
            }
            $stat = fstat($handle);
            $head = (string) fread($handle, CronHeartbeatWriter::HEAD_BYTES);
            fseek($handle, max(0, (int) ($stat['size'] ?? 0) - self::TAIL_BYTES));

            return new CronHeartbeatSnapshot($head, (string) stream_get_contents($handle), $stat['mtime'] ?? null);
        } finally {
            fclose($handle);
        }
    }

    /** @return list<int> */
    private function timestamps(string $chunk): array
    {
        // Both legacy pretty JSON and compact JSONL contain the same timestamp field.
        preg_match_all('/"ts":\s*"([^"]+)"/', $chunk, $matches);

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
