<?php

declare(strict_types=1);

namespace Mata\Models\ServerMetrics;

use DateTimeImmutable;
use Mata\Database\DatabaseInterface;
use Mata\Models\ServerMetrics\Dto\ServerMetricDailyAggregate;
use Throwable;

/**
 * Persistence helper for the `server_metrics_daily` table.
 */
final class ServerMetricDaily
{
    private function __construct(
        private readonly int $serverId,
        private readonly ServerMetricDailyAggregate $aggregate
    ) {}

    public static function fromAggregate(int $serverId, ServerMetricDailyAggregate $aggregate): self
    {
        return new self($serverId, $aggregate);
    }

    public function insertOrUpdate(DatabaseInterface $db): void
    {
        $db->beginTransaction();

        try {
            $db->runStatement(self::sql(), $this->buildParams());
            $db->commit();
        } catch (Throwable $exception) {
            $db->rollback();

            throw $exception;
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function buildParams(): array
    {
        return [
            'server_id' => $this->serverId,
            'date' => $this->formatDate($this->aggregate->date),
            'cpu_avg' => $this->aggregate->cpuAvg,
            'cpu_min' => $this->aggregate->cpuMin,
            'cpu_max' => $this->aggregate->cpuMax,
            'ram_used_avg' => $this->aggregate->ramUsedAvg,
            'ram_used_min' => $this->aggregate->ramUsedMin,
            'ram_used_max' => $this->aggregate->ramUsedMax,
            'ram_percent_avg' => $this->aggregate->ramPercentAvg,
            'ram_percent_max' => $this->aggregate->ramPercentMax,
            'disk_used_avg' => $this->aggregate->diskUsedAvg,
            'disk_used_min' => $this->aggregate->diskUsedMin,
            'disk_used_max' => $this->aggregate->diskUsedMax,
            'disk_percent_avg' => $this->aggregate->diskPercentAvg,
            'disk_percent_max' => $this->aggregate->diskPercentMax,
            'file_count_avg' => $this->aggregate->fileCountAvg,
            'file_count_max' => $this->aggregate->fileCountMax,
            'process_count_avg' => $this->aggregate->processCountAvg,
            'process_count_max' => $this->aggregate->processCountMax,
            'sample_count' => $this->aggregate->sampleCount,
            'alarm_count' => $this->aggregate->alarmCount,
            'warning_count' => $this->aggregate->warningCount,
        ];
    }

    private function formatDate(DateTimeImmutable $dateTime): string
    {
        return $dateTime->format('Y-m-d');
    }

    // NOTE: MySQL-only `ON DUPLICATE KEY UPDATE` upsert (SQLite uses
    // `ON CONFLICT ... DO UPDATE`); not runnable against the SQLite test harness.
    private static function sql(): string
    {
        return <<<'SQL'
INSERT INTO server_metrics_daily (
    server_id,
    date,
    cpu_avg,
    cpu_min,
    cpu_max,
    ram_used_avg,
    ram_used_min,
    ram_used_max,
    ram_percent_avg,
    ram_percent_max,
    disk_used_avg,
    disk_used_min,
    disk_used_max,
    disk_percent_avg,
    disk_percent_max,
    file_count_avg,
    file_count_max,
    process_count_avg,
    process_count_max,
    sample_count,
    alarm_count,
    warning_count
)
VALUES (
    :server_id,
    :date,
    :cpu_avg,
    :cpu_min,
    :cpu_max,
    :ram_used_avg,
    :ram_used_min,
    :ram_used_max,
    :ram_percent_avg,
    :ram_percent_max,
    :disk_used_avg,
    :disk_used_min,
    :disk_used_max,
    :disk_percent_avg,
    :disk_percent_max,
    :file_count_avg,
    :file_count_max,
    :process_count_avg,
    :process_count_max,
    :sample_count,
    :alarm_count,
    :warning_count
)
ON DUPLICATE KEY UPDATE
    cpu_avg = VALUES(cpu_avg),
    cpu_min = VALUES(cpu_min),
    cpu_max = VALUES(cpu_max),
    ram_used_avg = VALUES(ram_used_avg),
    ram_used_min = VALUES(ram_used_min),
    ram_used_max = VALUES(ram_used_max),
    ram_percent_avg = VALUES(ram_percent_avg),
    ram_percent_max = VALUES(ram_percent_max),
    disk_used_avg = VALUES(disk_used_avg),
    disk_used_min = VALUES(disk_used_min),
    disk_used_max = VALUES(disk_used_max),
    disk_percent_avg = VALUES(disk_percent_avg),
    disk_percent_max = VALUES(disk_percent_max),
    file_count_avg = VALUES(file_count_avg),
    file_count_max = VALUES(file_count_max),
    process_count_avg = VALUES(process_count_avg),
    process_count_max = VALUES(process_count_max),
    sample_count = VALUES(sample_count),
    alarm_count = VALUES(alarm_count),
    warning_count = VALUES(warning_count)
SQL;
    }
}
