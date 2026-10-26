<?php

declare(strict_types=1);

namespace Mata\Services\Cron;

use Mata\Utils\PathResolver;
use RuntimeException;

/**
 * Removes heartbeat files of jobs no longer in cron/; current jobs are never pruned by age.
 */
final class CronHeartbeatPruner
{
    public function __construct(
        private readonly CronHeartbeatReader $reader,
        private readonly string $heartbeatDir,
    ) {}

    public static function fromPaths(PathResolver $paths): self
    {
        return new self(CronHeartbeatReader::fromPaths($paths), $paths->cronLogsPath);
    }

    /** @return int Number of heartbeat files removed */
    public function prune(): int
    {
        $current = $this->currentHeartbeatFiles();

        $removed = 0;
        foreach (glob(CronJob::heartbeatFile($this->heartbeatDir, '*')) ?: [] as $file) {
            if (isset($current[$file]) || is_link($file) || !is_file($file)) {
                continue;
            }
            $handle = fopen($file, 'rb');
            if ($handle === false) {
                throw new RuntimeException("Cannot open stale heartbeat: {$file}");
            }

            try {
                if (!flock($handle, LOCK_EX | LOCK_NB)) {
                    continue; // a reader or a final write from an obsolete process
                }
                if (!unlink($file)) {
                    throw new RuntimeException("Cannot remove stale heartbeat: {$file}");
                }
                ++$removed;
            } finally {
                fclose($handle);
            }
        }

        return $removed;
    }

    /** @return array<string,true> Heartbeat paths of the entry points in cron/ */
    private function currentHeartbeatFiles(): array
    {
        $jobs = $this->reader->jobs();
        // Fail closed if the checkout is missing or unreadable, not "all jobs removed".
        if ($jobs === []) {
            throw new RuntimeException('Cannot prune heartbeats without current cron entry points.');
        }

        $files = [];
        foreach ($jobs as $job) {
            $files[CronJob::heartbeatFile($this->heartbeatDir, $job)] = true;
        }

        return $files;
    }
}
