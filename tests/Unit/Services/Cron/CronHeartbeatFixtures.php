<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services\Cron;

use Mata\Services\Cron\CronHeartbeatReader;

/**
 * Temporary cron/ and logs/ directories with heartbeat files.
 */
trait CronHeartbeatFixtures
{
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

    private static function heartbeatTime(int $timestamp): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $timestamp);
    }

    /**
     * Legacy pretty-printed heartbeat format.
     *
     * @param list<int> $heartbeats
     */
    private function writeHeartbeats(string $job, array $heartbeats): string
    {
        $file = "{$this->directory}/logs/heartbeat-{$job}.jsonl";
        foreach ($heartbeats as $timestamp) {
            file_put_contents($file, json_encode([
                'ts' => self::heartbeatTime($timestamp),
                'job' => $job,
                'status' => 'ok',
                'duration_ms' => 0.2,
            ], JSON_PRETTY_PRINT) . PHP_EOL, FILE_APPEND);
        }

        return $file;
    }

    /**
     * Records of a compact JSONL heartbeat file; fails on any line that is not one record.
     *
     * @return list<array<string,mixed>>
     */
    private function readRecords(string $file): array
    {
        return array_map(
            static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
            explode("\n", trim((string) file_get_contents($file))),
        );
    }

    private function reader(): CronHeartbeatReader
    {
        return new CronHeartbeatReader("{$this->directory}/cron", "{$this->directory}/logs");
    }
}
