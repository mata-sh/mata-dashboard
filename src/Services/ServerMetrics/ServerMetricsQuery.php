<?php

declare(strict_types=1);

namespace Mata\Services\ServerMetrics;

use DateTimeImmutable;
use DateTimeZone;
use Mata\Database\DatabaseInterface;
use Mata\Models\ServerMetrics\Dto\ServerMetricDailyAggregate;
use Mata\Models\ServerMetrics\Dto\ServerMetricHourlyAggregate;
use Mata\Models\ServerMetrics\Dto\ServerMetricRawSnapshot;
use Mata\Services\Identity\ServerApplicationIdResolver;
use RuntimeException;
use Throwable;

/**
 * Read-only query layer for server metrics.
 *
 * Lightweight class focused solely on fetching historical metrics
 * for charts and analytics. Does not handle write operations.
 */
final class ServerMetricsQuery implements ServerMetricsQueryInterface
{
    private DatabaseInterface $db;
    private ServerApplicationIdResolver $idResolver;

    public function __construct(DatabaseInterface $db, ?ServerApplicationIdResolver $idResolver = null)
    {
        $this->db = $db;
        $this->idResolver = $idResolver ?? new ServerApplicationIdResolver();
    }

    public function getHourlyMetrics(
        string $serverName,
        DateTimeImmutable $from,
        DateTimeImmutable $to
    ): array {
        $serverContext = $this->idResolver->resolveServer($serverName);
        $serverId = $serverContext['id'];
        if ($serverId === null) {
            return [];
        }

        $sql = <<<'SQL'
            SELECT
                h.hour_timestamp,
                h.cpu_avg,
                h.cpu_min,
                h.cpu_max,
                h.ram_used_avg,
                h.ram_used_min,
                h.ram_used_max,
                h.ram_percent_avg,
                h.ram_percent_max,
                h.disk_used_avg,
                h.disk_used_min,
                h.disk_used_max,
                h.disk_percent_avg,
                h.disk_percent_max,
                h.file_count_avg,
                h.file_count_max,
                h.process_count_avg,
                h.process_count_max,
                h.sample_count,
                h.first_sample,
                h.last_sample
            FROM server_metrics_hourly AS h
            WHERE h.server_id = ?
                AND h.hour_timestamp >= ?
                AND h.hour_timestamp < ?
            ORDER BY h.hour_timestamp ASC
        SQL;

        $rows = $this->db->runSelect($sql, [
            $serverId,
            $from->format('Y-m-d H:i:s'),
            $to->format('Y-m-d H:i:s'),
        ]);

        $results = [];
        foreach ($rows as $row) {
            $results[] = $this->mapRowToHourlyAggregate($row);
        }

        return $results;
    }

    public function getDailyMetrics(
        string $serverName,
        DateTimeImmutable $from,
        DateTimeImmutable $to
    ): array {
        $serverContext = $this->idResolver->resolveServer($serverName);
        $serverId = $serverContext['id'];
        if ($serverId === null) {
            return [];
        }

        $sql = <<<'SQL'
            SELECT
                d.date,
                d.cpu_avg,
                d.cpu_min,
                d.cpu_max,
                d.ram_used_avg,
                d.ram_used_min,
                d.ram_used_max,
                d.ram_percent_avg,
                d.ram_percent_max,
                d.disk_used_avg,
                d.disk_used_min,
                d.disk_used_max,
                d.disk_percent_avg,
                d.disk_percent_max,
                d.file_count_avg,
                d.file_count_max,
                d.process_count_avg,
                d.process_count_max,
                d.sample_count,
                d.alarm_count,
                d.warning_count
            FROM server_metrics_daily AS d
            WHERE d.server_id = ?
                AND d.date >= ?
                AND d.date < ?
            ORDER BY d.date ASC
        SQL;

        $rows = $this->db->runSelect($sql, [
            $serverId,
            $from->format('Y-m-d'),
            $to->format('Y-m-d'),
        ]);

        $results = [];
        foreach ($rows as $row) {
            $results[] = $this->mapRowToDailyAggregate($row);
        }

        return $results;
    }

    public function getRawMetrics(
        string $serverName,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        int $limit = 0
    ): array {
        $serverContext = $this->idResolver->resolveServer($serverName);
        $serverId = $serverContext['id'];
        if ($serverId === null) {
            return [];
        }

        $sql = <<<'SQL'
            SELECT
                r.collected_at,
                r.ingested_at,
                r.cpu_usage,
                r.ram_used,
                r.ram_total,
                r.disk_used,
                r.disk_total,
                r.ram_usage_percent,
                r.disk_usage_percent,
                r.total_file_count,
                r.process_count,
                r.php_process_count,
                r.php_version,
                r.os_version,
                r.server_uptime
            FROM server_metrics_raw AS r
            WHERE r.server_id = ?
                AND r.collected_at >= ?
                AND r.collected_at < ?
            ORDER BY r.collected_at ASC
        SQL;

        if ($limit > 0) {
            $sql .= ' LIMIT ' . (int) $limit;
        }

        $rows = $this->db->runSelect($sql, [
            $serverId,
            $from->format('Y-m-d H:i:s'),
            $to->format('Y-m-d H:i:s'),
        ]);

        $results = [];
        foreach ($rows as $row) {
            $results[] = $this->mapRowToRawSnapshot($row);
        }

        return $results;
    }

    public function getAvailableServers(): array
    {
        $sql = <<<'SQL'
            SELECT DISTINCT s.name
            FROM servers AS s
            INNER JOIN server_metrics_raw AS r ON r.server_id = s.id
            ORDER BY s.name ASC
        SQL;

        $rows = $this->db->runSelect($sql, []);

        $servers = [];
        foreach ($rows as $row) {
            $servers[] = (string) ($row['name'] ?? '');
        }

        return array_filter($servers);
    }

    /**
     * @param array<string,mixed> $row
     */
    private function mapRowToHourlyAggregate(array $row): ServerMetricHourlyAggregate
    {
        try {
            $hourTimestamp = new DateTimeImmutable((string) $row['hour_timestamp'], new DateTimeZone('UTC'));
        } catch (Throwable $exception) {
            throw new RuntimeException('Unable to parse hour_timestamp from database.', 0, $exception);
        }

        $firstSample = null;
        if (!empty($row['first_sample'])) {
            try {
                $firstSample = new DateTimeImmutable((string) $row['first_sample'], new DateTimeZone('UTC'));
            } catch (Throwable) {
                // Ignore parse failures for optional fields
            }
        }

        $lastSample = null;
        if (!empty($row['last_sample'])) {
            try {
                $lastSample = new DateTimeImmutable((string) $row['last_sample'], new DateTimeZone('UTC'));
            } catch (Throwable) {
                // Ignore parse failures for optional fields
            }
        }

        return new ServerMetricHourlyAggregate(
            $hourTimestamp,
            $this->toNullableFloat($row['cpu_avg'] ?? null),
            $this->toNullableFloat($row['cpu_min'] ?? null),
            $this->toNullableFloat($row['cpu_max'] ?? null),
            $this->toNullableInt($row['ram_used_avg'] ?? null),
            $this->toNullableInt($row['ram_used_min'] ?? null),
            $this->toNullableInt($row['ram_used_max'] ?? null),
            $this->toNullableFloat($row['ram_percent_avg'] ?? null),
            $this->toNullableFloat($row['ram_percent_max'] ?? null),
            $this->toNullableInt($row['disk_used_avg'] ?? null),
            $this->toNullableInt($row['disk_used_min'] ?? null),
            $this->toNullableInt($row['disk_used_max'] ?? null),
            $this->toNullableFloat($row['disk_percent_avg'] ?? null),
            $this->toNullableFloat($row['disk_percent_max'] ?? null),
            $this->toNullableInt($row['file_count_avg'] ?? null),
            $this->toNullableInt($row['file_count_max'] ?? null),
            $this->toNullableInt($row['process_count_avg'] ?? null),
            $this->toNullableInt($row['process_count_max'] ?? null),
            (int) ($row['sample_count'] ?? 0),
            $firstSample,
            $lastSample
        );
    }

    /**
     * @param array<string,mixed> $row
     */
    private function mapRowToDailyAggregate(array $row): ServerMetricDailyAggregate
    {
        try {
            $date = new DateTimeImmutable((string) $row['date'], new DateTimeZone('UTC'));
        } catch (Throwable $exception) {
            throw new RuntimeException('Unable to parse date from database.', 0, $exception);
        }

        return new ServerMetricDailyAggregate(
            $date,
            $this->toNullableFloat($row['cpu_avg'] ?? null),
            $this->toNullableFloat($row['cpu_min'] ?? null),
            $this->toNullableFloat($row['cpu_max'] ?? null),
            $this->toNullableInt($row['ram_used_avg'] ?? null),
            $this->toNullableInt($row['ram_used_min'] ?? null),
            $this->toNullableInt($row['ram_used_max'] ?? null),
            $this->toNullableFloat($row['ram_percent_avg'] ?? null),
            $this->toNullableFloat($row['ram_percent_max'] ?? null),
            $this->toNullableInt($row['disk_used_avg'] ?? null),
            $this->toNullableInt($row['disk_used_min'] ?? null),
            $this->toNullableInt($row['disk_used_max'] ?? null),
            $this->toNullableFloat($row['disk_percent_avg'] ?? null),
            $this->toNullableFloat($row['disk_percent_max'] ?? null),
            $this->toNullableInt($row['file_count_avg'] ?? null),
            $this->toNullableInt($row['file_count_max'] ?? null),
            $this->toNullableInt($row['process_count_avg'] ?? null),
            $this->toNullableInt($row['process_count_max'] ?? null),
            (int) ($row['sample_count'] ?? 0),
            (int) ($row['alarm_count'] ?? 0),
            (int) ($row['warning_count'] ?? 0)
        );
    }

    /**
     * @param array<string,mixed> $row
     */
    private function mapRowToRawSnapshot(array $row): ServerMetricRawSnapshot
    {
        try {
            $collectedAt = new DateTimeImmutable((string) $row['collected_at'], new DateTimeZone('UTC'));
        } catch (Throwable $exception) {
            throw new RuntimeException('Unable to parse collected_at from database.', 0, $exception);
        }

        try {
            $ingestedAt = new DateTimeImmutable((string) $row['ingested_at'], new DateTimeZone('UTC'));
        } catch (Throwable $exception) {
            throw new RuntimeException('Unable to parse ingested_at from database.', 0, $exception);
        }

        return new ServerMetricRawSnapshot(
            $collectedAt,
            $ingestedAt,
            $this->toNullableFloat($row['cpu_usage'] ?? null),
            $this->toNullableInt($row['ram_used'] ?? null),
            $this->toNullableInt($row['ram_total'] ?? null),
            $this->toNullableInt($row['disk_used'] ?? null),
            $this->toNullableInt($row['disk_total'] ?? null),
            $this->toNullableFloat($row['ram_usage_percent'] ?? null),
            $this->toNullableFloat($row['disk_usage_percent'] ?? null),
            $this->toNullableInt($row['total_file_count'] ?? null),
            $this->toNullableInt($row['process_count'] ?? null),
            $this->toNullableInt($row['php_process_count'] ?? null),
            $this->normalizeString($row['php_version'] ?? null),
            $this->normalizeString($row['os_version'] ?? null),
            $this->toNullableInt($row['server_uptime'] ?? null)
        );
    }

    private function toNullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function toNullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
    }

    private function normalizeString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
