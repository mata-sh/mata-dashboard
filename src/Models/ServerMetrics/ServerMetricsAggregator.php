<?php

declare(strict_types=1);

namespace Mata\Models\ServerMetrics;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use Mata\Core\DatabaseInterface;
use Mata\Models\ServerMetrics\Dto\ServerMetricDailyAggregate;
use Mata\Models\ServerMetrics\Dto\ServerMetricHourlyAggregate;
use Mata\Utils\TypeConverter;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Aggregates raw server metrics into hourly and daily rollups.
 *
 * Responsibilities:
 * - Querying raw samples for a fixed window
 * - Calculating min/max/avg totals per timeframe
 * - Counting alarm/warning events from dashboard logs
 * - Delegating persistence to the metrics repository
 * - Coordinating raw data pruning after aggregation
 */
final class ServerMetricsAggregator
{
    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly ServerMetricsRepository $repository,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * Aggregate raw samples for a given hour (inclusive of HH:00, exclusive of the next hour).
     */
    public function aggregateHour(string $serverName, DateTimeImmutable $hourStart): bool
    {
        $stats = $this->fetchHourlyStats($serverName, $hourStart);
        if ($stats === null || ((int) ($stats['sample_count'] ?? 0)) === 0) {
            return false;
        }

        $aggregate = $this->mapHourlyAggregate($stats, $hourStart);
        $this->repository->upsertHourlyAggregate($serverName, $aggregate);

        return true;
    }

    /**
     * Aggregate raw samples for a given day (midnight start, exclusive of next midnight).
     */
    public function aggregateDay(string $serverName, DateTimeImmutable $dayDate): bool
    {
        $stats = $this->fetchDailyStats($serverName, $dayDate);
        if ($stats === null || ((int) ($stats['sample_count'] ?? 0)) === 0) {
            return false;
        }

        $eventCounts = $this->fetchEventCounts($serverName, $dayDate);

        $aggregate = $this->mapDailyAggregate(
            $stats,
            $dayDate,
            $eventCounts['alarms'],
            $eventCounts['warnings']
        );

        $this->repository->upsertDailyAggregate($serverName, $aggregate);

        return true;
    }

    /**
     * Delete raw samples older than the provided timestamp.
     *
     * @return int number of rows removed
     */
    public function pruneRawData(DateTimeImmutable $before, int $batchLimit = 0): int
    {
        $sql = 'DELETE FROM server_metrics_raw WHERE collected_at < ?';

        if ($batchLimit > 0) {
            $sql .= ' LIMIT ' . (int) $batchLimit;
        }

        try {
            $deleted = $this->db->runQuery($sql, [$before->format('Y-m-d H:i:s')]);
        } catch (Throwable $exception) {
            $this->logger->error('server_metrics.prune_failed', [
                'before' => $before->format(DateTimeInterface::ATOM),
                'message' => $exception->getMessage(),
            ]);

            return 0;
        }

        return is_int($deleted) ? $deleted : 0;
    }

    /**
     * @return null|array<string,mixed>
     */
    private function fetchHourlyStats(string $serverName, DateTimeImmutable $hourStart): ?array
    {
        $hourEnd = $hourStart->add(new DateInterval('PT1H'));

        $sql = <<<'SQL'
SELECT
    COUNT(*) AS sample_count,
    MIN(r.collected_at) AS first_sample,
    MAX(r.collected_at) AS last_sample,
    AVG(r.cpu_usage) AS cpu_avg,
    MIN(r.cpu_usage) AS cpu_min,
    MAX(r.cpu_usage) AS cpu_max,
    AVG(r.ram_used) AS ram_used_avg,
    MIN(r.ram_used) AS ram_used_min,
    MAX(r.ram_used) AS ram_used_max,
    AVG(r.ram_usage_percent) AS ram_percent_avg,
    MAX(r.ram_usage_percent) AS ram_percent_max,
    AVG(r.disk_used) AS disk_used_avg,
    MIN(r.disk_used) AS disk_used_min,
    MAX(r.disk_used) AS disk_used_max,
    AVG(r.disk_usage_percent) AS disk_percent_avg,
    MAX(r.disk_usage_percent) AS disk_percent_max,
    AVG(r.total_file_count) AS file_count_avg,
    AVG(r.process_count) AS process_count_avg,
    MAX(r.process_count) AS process_count_max
FROM server_metrics_raw AS r
INNER JOIN servers AS s ON s.id = r.server_id
WHERE
    s.name = ?
    AND r.collected_at >= ?
    AND r.collected_at < ?
SQL;

        $rows = $this->db->runQuery($sql, [
            $serverName,
            $hourStart->format('Y-m-d H:i:s'),
            $hourEnd->format('Y-m-d H:i:s'),
        ]);

        return $rows[0] ?? null;
    }

    /**
     * @return null|array<string,mixed>
     */
    private function fetchDailyStats(string $serverName, DateTimeImmutable $dayDate): ?array
    {
        $startOfDay = $dayDate->setTime(0, 0, 0);
        $dayEnd = $startOfDay->add(new DateInterval('P1D'));

        $sql = <<<'SQL'
SELECT
    COUNT(*) AS sample_count,
    AVG(r.cpu_usage) AS cpu_avg,
    MIN(r.cpu_usage) AS cpu_min,
    MAX(r.cpu_usage) AS cpu_max,
    AVG(r.ram_used) AS ram_used_avg,
    MIN(r.ram_used) AS ram_used_min,
    MAX(r.ram_used) AS ram_used_max,
    AVG(r.ram_usage_percent) AS ram_percent_avg,
    MAX(r.ram_usage_percent) AS ram_percent_max,
    AVG(r.disk_used) AS disk_used_avg,
    MIN(r.disk_used) AS disk_used_min,
    MAX(r.disk_used) AS disk_used_max,
    AVG(r.disk_usage_percent) AS disk_percent_avg,
    MAX(r.disk_usage_percent) AS disk_percent_max,
    AVG(r.total_file_count) AS file_count_avg,
    MAX(r.total_file_count) AS file_count_max,
    AVG(r.process_count) AS process_count_avg,
    MAX(r.process_count) AS process_count_max
FROM server_metrics_raw AS r
INNER JOIN servers AS s ON s.id = r.server_id
WHERE
    s.name = ?
    AND r.collected_at >= ?
    AND r.collected_at < ?
SQL;

        $rows = $this->db->runQuery($sql, [
            $serverName,
            $startOfDay->format('Y-m-d H:i:s'),
            $dayEnd->format('Y-m-d H:i:s'),
        ]);

        return $rows[0] ?? null;
    }

    /**
     * @return array{alarms:int,warnings:int}
     */
    private function fetchEventCounts(string $serverName, DateTimeImmutable $dayDate): array
    {
        $startOfDay = $dayDate->setTime(0, 0, 0);
        $dayEnd = $startOfDay->add(new DateInterval('P1D'));

        $sql = <<<'SQL'
SELECT
    SUM(CASE WHEN e.level = 'critical' THEN 1 ELSE 0 END) AS alarm_count,
    SUM(CASE WHEN e.level = 'warning' THEN 1 ELSE 0 END) AS warning_count
FROM events AS e
INNER JOIN servers AS s ON s.id = e.server_id
WHERE
    s.name = ?
    AND e.datetime >= ?
    AND e.datetime < ?
SQL;

        try {
            $rows = $this->db->runQuery($sql, [
                $serverName,
                $startOfDay->format('Y-m-d H:i:s'),
                $dayEnd->format('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $exception) {
            $this->logger->warning('server_metrics.event_count_failed', [
                'server' => $serverName,
                'date' => $dayDate->format('Y-m-d'),
                'message' => $exception->getMessage(),
            ]);

            return ['alarms' => 0, 'warnings' => 0];
        }

        $row = $rows[0] ?? [];

        return [
            'alarms' => (int) ($row['alarm_count'] ?? 0),
            'warnings' => (int) ($row['warning_count'] ?? 0),
        ];
    }

    /**
     * @param array<string,mixed> $row
     */
    private function mapHourlyAggregate(array $row, DateTimeImmutable $hourStart): ServerMetricHourlyAggregate
    {
        return new ServerMetricHourlyAggregate(
            $hourStart,
            TypeConverter::toFloat($row['cpu_avg'] ?? null),
            TypeConverter::toFloat($row['cpu_min'] ?? null),
            TypeConverter::toFloat($row['cpu_max'] ?? null),
            TypeConverter::toInt($row['ram_used_avg'] ?? null),
            TypeConverter::toInt($row['ram_used_min'] ?? null),
            TypeConverter::toInt($row['ram_used_max'] ?? null),
            TypeConverter::toFloat($row['ram_percent_avg'] ?? null),
            TypeConverter::toFloat($row['ram_percent_max'] ?? null),
            TypeConverter::toInt($row['disk_used_avg'] ?? null),
            TypeConverter::toInt($row['disk_used_min'] ?? null),
            TypeConverter::toInt($row['disk_used_max'] ?? null),
            TypeConverter::toFloat($row['disk_percent_avg'] ?? null),
            TypeConverter::toFloat($row['disk_percent_max'] ?? null),
            TypeConverter::toInt($row['file_count_avg'] ?? null),
            TypeConverter::toInt($row['process_count_avg'] ?? null),
            TypeConverter::toInt($row['process_count_max'] ?? null),
            (int) ($row['sample_count'] ?? 0),
            TypeConverter::toDateTime($row['first_sample'] ?? null),
            TypeConverter::toDateTime($row['last_sample'] ?? null)
        );
    }

    /**
     * @param array<string,mixed> $row
     */
    private function mapDailyAggregate(
        array $row,
        DateTimeImmutable $dayDate,
        int $alarmCount,
        int $warningCount
    ): ServerMetricDailyAggregate {
        return new ServerMetricDailyAggregate(
            $dayDate,
            TypeConverter::toFloat($row['cpu_avg'] ?? null),
            TypeConverter::toFloat($row['cpu_min'] ?? null),
            TypeConverter::toFloat($row['cpu_max'] ?? null),
            TypeConverter::toInt($row['ram_used_avg'] ?? null),
            TypeConverter::toInt($row['ram_used_min'] ?? null),
            TypeConverter::toInt($row['ram_used_max'] ?? null),
            TypeConverter::toFloat($row['ram_percent_avg'] ?? null),
            TypeConverter::toFloat($row['ram_percent_max'] ?? null),
            TypeConverter::toInt($row['disk_used_avg'] ?? null),
            TypeConverter::toInt($row['disk_used_min'] ?? null),
            TypeConverter::toInt($row['disk_used_max'] ?? null),
            TypeConverter::toFloat($row['disk_percent_avg'] ?? null),
            TypeConverter::toFloat($row['disk_percent_max'] ?? null),
            TypeConverter::toInt($row['file_count_avg'] ?? null),
            TypeConverter::toInt($row['file_count_max'] ?? null),
            TypeConverter::toInt($row['process_count_avg'] ?? null),
            TypeConverter::toInt($row['process_count_max'] ?? null),
            (int) ($row['sample_count'] ?? 0),
            max(0, $alarmCount),
            max(0, $warningCount)
        );
    }
}
