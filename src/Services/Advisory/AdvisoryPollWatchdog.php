<?php

declare(strict_types=1);

namespace Mata\Services\Advisory;

use Mata\Config\Monitoring\Section\AdvisoriesConfig;
use Mata\Services\Cron\CronJob;
use Mata\Services\Event\Dto\EventCommand;
use Mata\Services\Event\EventManager;

/**
 * Warns when cron/advisories_poll.php stopped running: a poller that never runs would look
 * exactly like sources without news. Runs from another cron job, since a stopped job cannot
 * report itself. A poller that never ran is pending (fresh install), not stalled.
 */
final class AdvisoryPollWatchdog
{
    public const EVENT_POLL_STALLED = 'advisories.poll_stalled';
    public const POLL_JOB = 'advisories-poll';

    public function __construct(
        private AdvisoriesConfig $config,
        private EventManager $eventManager,
        private string $heartbeatDir,
    ) {}

    /** @return null|int minutes since the last poll heartbeat, when that is too long ago */
    public function check(int $now): ?int
    {
        if ($this->config->enabledSources() === []) {
            return null;
        }
        $lastRun = $this->lastRun();
        if ($lastRun === null) {
            return null;
        }

        $minutes = intdiv(max(0, $now - $lastRun), 60);
        if ($minutes < $this->config->staleAfterMinutes) {
            return null;
        }

        $this->eventManager->handle(new EventCommand(
            type: self::EVENT_POLL_STALLED,
            message: sprintf('Security advisory polling stopped: cron/advisories_poll.php last ran %d minutes ago', $minutes),
            level: 'warning',
            context: ['job' => self::POLL_JOB, 'additional_data' => [
                'last_run' => gmdate('Y-m-d H:i:s', $lastRun),
                'stale_after_minutes' => $this->config->staleAfterMinutes,
            ]],
        ));

        return $minutes;
    }

    /** Unix time of the last poll heartbeat; the file's mtime, as CronHeartbeatReader reads it. */
    public function lastRun(): ?int
    {
        $file = CronJob::heartbeatFile($this->heartbeatDir, self::POLL_JOB);
        clearstatcache(true, $file);
        $mtime = is_readable($file) ? filemtime($file) : false;

        return $mtime === false ? null : $mtime;
    }
}
