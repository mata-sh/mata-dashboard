<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services\Cron;

use Mata\Services\Cron\CronHeartbeatPruner;
use Mata\Tests\TestCase;

/**
 * @covers \Mata\Services\Cron\CronHeartbeatPruner
 *
 * @internal
 */
final class CronHeartbeatPrunerTest extends TestCase
{
    use CronHeartbeatFixtures;

    private const NOW = 1_760_000_000;

    public function testKeepsOldCurrentJobsAndIgnoresUnrelatedFiles(): void
    {
        touch("{$this->directory}/cron/weekly_job.php");
        $current = $this->writeHeartbeats('weekly-job', [self::NOW - 1_000_000]);
        touch($current, self::NOW - 1_000_000);
        $obsolete = $this->writeHeartbeats('renamed-job', [self::NOW]);
        $unrelated = "{$this->directory}/logs/app.log";
        touch($unrelated);

        $this->assertSame(1, $this->pruner()->prune());

        $this->assertFileExists($current);
        $this->assertFileDoesNotExist($obsolete);
        $this->assertFileExists($unrelated);
    }

    public function testSkipsObsoleteHeartbeatThatIsStillLocked(): void
    {
        touch("{$this->directory}/cron/weekly_job.php");
        $busy = $this->writeHeartbeats('busy-obsolete-job', [self::NOW]);
        $handle = fopen($busy, 'rb');
        flock($handle, LOCK_SH);

        try {
            $this->assertSame(0, $this->pruner()->prune());
        } finally {
            fclose($handle);
        }

        $this->assertFileExists($busy);
    }

    private function pruner(): CronHeartbeatPruner
    {
        return new CronHeartbeatPruner($this->reader(), "{$this->directory}/logs");
    }
}
