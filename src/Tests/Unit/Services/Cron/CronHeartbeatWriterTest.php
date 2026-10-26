<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services\Cron;

use Mata\Config\Dto\LoggingConfig;
use Mata\Services\Cron\CronHeartbeatWriter;
use Mata\Tests\TestCase;

/**
 * @covers \Mata\Services\Cron\CronHeartbeatWriter
 *
 * @internal
 */
final class CronHeartbeatWriterTest extends TestCase
{
    use CronHeartbeatFixtures;

    private const NOW = 1_760_000_000;

    /** The writer's retained history plus its first-record window. */
    private const MAX_COMPACTED_BYTES = CronHeartbeatWriter::RETAIN_BYTES + CronHeartbeatWriter::HEAD_BYTES;

    public function testCompactionKeepsFirstHeartbeatRecentHistoryAndNewEntry(): void
    {
        touch("{$this->directory}/cron/alarms_dispatch.php");
        $timestamps = range(self::NOW - 120_000, self::NOW - 60, 60);
        $file = $this->writeHeartbeats('alarms-dispatch', $timestamps);
        $this->assertGreaterThan(LoggingConfig::CRON_HEARTBEAT_MAX_BYTES_LOWER_BOUND, filesize($file));

        $this->writer()->append($file, ['ts' => self::heartbeatTime(self::NOW), 'job' => 'alarms-dispatch']);

        $records = $this->readRecords($file);
        $history = array_column(array_slice($records, 1, -1), 'ts');
        $this->assertSame(self::heartbeatTime($timestamps[0]), $records[0]['ts']);
        // Retained history is the complete, ordered end of the original; nothing fabricated.
        $this->assertGreaterThan(1, count($history));
        $this->assertSame(array_map(self::heartbeatTime(...), array_slice($timestamps, -count($history))), $history);
        $this->assertSame(self::heartbeatTime(self::NOW), $records[array_key_last($records)]['ts']);
        $this->assertLessThan(self::MAX_COMPACTED_BYTES, strlen((string) file_get_contents($file)));

        touch($file, self::NOW);
        $status = $this->reader()->read(self::NOW)[0];

        $this->assertSame(self::NOW, $status->lastRun);
        $this->assertSame(60, $status->interval);
    }

    public function testConcurrentAppendsDuringCompactionKeepEveryRecord(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl required for concurrent writers.');
        }
        touch("{$this->directory}/cron/concurrent.php");
        $file = $this->writeHeartbeats('concurrent', range(self::NOW - 120_000, self::NOW, 60));

        $children = [];
        for ($child = 0; $child < 3; ++$child) {
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid);
            if ($pid === 0) {
                $writer = $this->writer();
                for ($i = 0; $i < 30; ++$i) {
                    $writer->append($file, ['ts' => '2026-01-01T00:00:00Z', 'id' => "{$child}:{$i}"]);
                }

                exit(0);
            }
            $children[] = $pid;
        }
        // Reads in between must never see a half-compacted file.
        for ($i = 0; $i < 15; ++$i) {
            $status = $this->reader()->read(time())[0];
            $this->assertNotNull($status->lastRun);
            $this->assertSame(60, $status->interval);
        }
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $exitStatus);
            $this->assertSame(0, pcntl_wexitstatus($exitStatus));
        }

        $this->assertCount(90, array_unique(array_column($this->readRecords($file), 'id')));
    }

    private function writer(): CronHeartbeatWriter
    {
        return new CronHeartbeatWriter(LoggingConfig::CRON_HEARTBEAT_MAX_BYTES_LOWER_BOUND);
    }
}
