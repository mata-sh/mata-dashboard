<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services\Cron;

use Mata\Services\Cron\Dto\CronJobState;
use Mata\Services\Cron\Dto\CronJobStatus;
use Mata\Tests\TestCase;

/**
 * @covers \Mata\Services\Cron\CronHeartbeatReader
 *
 * @internal
 */
final class CronHeartbeatReaderTest extends TestCase
{
    use CronHeartbeatFixtures;

    private const NOW = 1_760_000_000;

    public function testProgressHeartbeatsWithinARunDoNotShortenTheInterval(): void
    {
        // servers_data_fetch heartbeats once per server and once at the end of each 5-minute run.
        $heartbeats = [];
        for ($run = 5; $run >= 1; --$run) {
            $start = self::NOW - $run * 300;
            array_push($heartbeats, $start + 1, $start + 2, $start + 2);
        }

        $status = $this->readSingleJob('servers_data_fetch', $heartbeats, lastRun: self::NOW - 298);

        $this->assertSame(CronJobState::Ok, $status->state);
        $this->assertSame(300, $status->interval);
    }

    public function testJobThatStoppedRunningIsOverdue(): void
    {
        $heartbeats = [self::NOW - 1800, self::NOW - 1740, self::NOW - 1680, self::NOW - 1620];

        $status = $this->readSingleJob('alarms_dispatch', $heartbeats, lastRun: self::NOW - 1620);

        $this->assertSame(CronJobState::Overdue, $status->state);
        $this->assertSame(60, $status->interval);
    }

    public function testJobsWithoutEnoughHistory(): void
    {
        touch("{$this->directory}/cron/retention_cleanup.php");
        $this->writeHeartbeats('servers-metrics-aggregate', [self::NOW - 900, self::NOW]);
        touch("{$this->directory}/cron/servers_metrics_aggregate.php");

        $statuses = $this->reader()->read(self::NOW);

        $this->assertSame(['retention-cleanup', 'servers-metrics-aggregate'], array_map(
            static fn (CronJobStatus $status): string => $status->job,
            $statuses
        ));
        $this->assertSame(CronJobState::Pending, $statuses[0]->state);
        $this->assertSame(CronJobState::Unknown, $statuses[1]->state);
    }

    public function testJobsNeverRanWhileNoJobHasRun(): void
    {
        touch("{$this->directory}/cron/retention_cleanup.php");

        $this->assertSame(CronJobState::Never, $this->reader()->read(self::NOW)[0]->state);
    }

    public function testJobStillMissingADayAfterCronStartedIsNotInTheCrontab(): void
    {
        // e.g. an upgrade added libraries_audit and the native crontab was not extended.
        touch("{$this->directory}/cron/libraries_audit.php");
        $this->writeHeartbeats('alarms-dispatch', [self::NOW - 90_000, self::NOW - 60]);
        touch("{$this->directory}/cron/alarms_dispatch.php");

        $statuses = $this->reader()->read(self::NOW);

        $this->assertSame('libraries-audit', $statuses[1]->job);
        $this->assertSame(CronJobState::Never, $statuses[1]->state);
    }

    /**
     * @param list<int> $heartbeats
     */
    private function readSingleJob(string $entryPoint, array $heartbeats, int $lastRun): CronJobStatus
    {
        touch("{$this->directory}/cron/{$entryPoint}.php");
        $file = $this->writeHeartbeats(str_replace('_', '-', $entryPoint), $heartbeats);
        touch($file, $lastRun);

        return $this->reader()->read(self::NOW)[0];
    }
}
