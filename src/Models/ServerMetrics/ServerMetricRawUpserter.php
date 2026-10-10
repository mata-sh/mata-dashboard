<?php

declare(strict_types=1);

namespace Mata\Models\ServerMetrics;

use DateTimeImmutable;
use Mata\Database\DatabaseInterface;
use Mata\Models\ServerMetrics\Dto\ServerMetricRawSnapshot;
use Throwable;

/**
 * Persistence helper for the `server_metrics_raw` table.
 *
 * Encapsulates SQL for insert/upsert operations and keeps the repository
 * focused on orchestration instead of string-concatenated queries.
 */
final class ServerMetricRawUpserter
{
    private function __construct(
        private readonly int $serverId,
        private readonly int $collectorId,
        private readonly ServerMetricRawSnapshot $snapshot,
        private readonly string $payloadHashHex
    ) {}

    public static function fromSnapshot(
        int $serverId,
        int $collectorId,
        ServerMetricRawSnapshot $snapshot,
        string $payloadHashHex
    ): self {
        return new self($serverId, $collectorId, $snapshot, $payloadHashHex);
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
            'collector_id' => $this->collectorId,
            'collected_at' => $this->formatDateTime($this->snapshot->collectedAt),
            'ingested_at' => $this->formatDateTime($this->snapshot->ingestedAt),
            'payload_hash' => $this->payloadHashHex,
            'cpu_usage' => $this->snapshot->cpuUsage,
            'ram_used' => $this->snapshot->ramUsed,
            'ram_total' => $this->snapshot->ramTotal,
            'disk_used' => $this->snapshot->diskUsed,
            'disk_total' => $this->snapshot->diskTotal,
            'ram_usage_percent' => $this->snapshot->ramUsagePercent,
            'disk_usage_percent' => $this->snapshot->diskUsagePercent,
            'total_file_count' => $this->snapshot->totalFileCount,
            'process_count' => $this->snapshot->processCount,
            'php_process_count' => $this->snapshot->phpProcessCount,
            'php_version' => $this->snapshot->phpVersion,
            'os_version' => $this->snapshot->osVersion,
            'server_uptime' => $this->snapshot->serverUptime,
        ];
    }

    private function formatDateTime(DateTimeImmutable $dateTime): string
    {
        $milliseconds = (int) floor((int) $dateTime->format('u') / 1000);
        $fraction = str_pad((string) $milliseconds, 3, '0', STR_PAD_LEFT);

        return $dateTime->format('Y-m-d H:i:s') . '.' . $fraction;
    }

    // NOTE: MySQL-only `ON DUPLICATE KEY UPDATE` upsert (SQLite uses
    // `ON CONFLICT ... DO UPDATE`); not runnable against the SQLite test harness.
    private static function sql(): string
    {
        return <<<'SQL'
INSERT INTO server_metrics_raw (
    server_id,
    collector_id,
    collected_at,
    ingested_at,
    payload_hash,
    cpu_usage,
    ram_used,
    ram_total,
    disk_used,
    disk_total,
    ram_usage_percent,
    disk_usage_percent,
    total_file_count,
    process_count,
    php_process_count,
    php_version,
    os_version,
    server_uptime
)
VALUES (
    :server_id,
    :collector_id,
    :collected_at,
    :ingested_at,
    UNHEX(:payload_hash),
    :cpu_usage,
    :ram_used,
    :ram_total,
    :disk_used,
    :disk_total,
    :ram_usage_percent,
    :disk_usage_percent,
    :total_file_count,
    :process_count,
    :php_process_count,
    :php_version,
    :os_version,
    :server_uptime
)
ON DUPLICATE KEY UPDATE
    ingested_at = VALUES(ingested_at),
    cpu_usage = VALUES(cpu_usage),
    ram_used = VALUES(ram_used),
    ram_total = VALUES(ram_total),
    disk_used = VALUES(disk_used),
    disk_total = VALUES(disk_total),
    ram_usage_percent = VALUES(ram_usage_percent),
    disk_usage_percent = VALUES(disk_usage_percent),
    total_file_count = VALUES(total_file_count),
    process_count = VALUES(process_count),
    php_process_count = VALUES(php_process_count),
    php_version = VALUES(php_version),
    os_version = VALUES(os_version),
    server_uptime = VALUES(server_uptime)
SQL;
    }
}
