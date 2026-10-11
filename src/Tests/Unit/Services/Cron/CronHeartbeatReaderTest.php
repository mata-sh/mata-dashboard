<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services\Cron;

use Mata\Services\Cron\CronHeartbeatReader;
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
    private const NOW = 1_760_000_000;

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/mata-cron-heartbeat-' . bin2hex(random_bytes(8));
        mkdir("{$this->directory}/cron", 0700, true);
        mkdir("{$this->directory}/logs", 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (['cron', 'logs'] as $subdirectory) {
            array_map('unlink', glob("{$this->directory}/{$subdirectory}/*") ?: []);
            rmdir("{$this->directory}/{$subdirectory}");
        }
        rmdir($this->directory);
    }

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
        $this->assertSame(CronJobState::Never, $statuses[0]->state);
        $this->assertSame(CronJobState::Unknown, $statuses[1]->state);
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

    /**
     * Same pretty-printed format CronJob::touchHeartbeat() appends.
     *
     * @param list<int> $heartbeats
     */
    private function writeHeartbeats(string $job, array $heartbeats): string
    {
        $file = "{$this->directory}/logs/heartbeat-{$job}.jsonl";
        foreach ($heartbeats as $timestamp) {
            file_put_contents($file, json_encode([
                'ts' => gmdate('Y-m-d\TH:i:s\Z', $timestamp),
                'job' => $job,
                'status' => 'ok',
                'duration_ms' => 0.2,
            ], JSON_PRETTY_PRINT) . PHP_EOL, FILE_APPEND);
        }

        return $file;
    }

    private function reader(): CronHeartbeatReader
    {
        return new CronHeartbeatReader("{$this->directory}/cron", "{$this->directory}/logs");
    }
}
