<?php

declare(strict_types=1);

namespace Mata\Models\ServerMetrics;

use DateTimeImmutable;
use Mata\Models\ServerMetrics\Dto\ServerMetricDailyAggregate;
use Mata\Models\ServerMetrics\Dto\ServerMetricHourlyAggregate;
use Mata\Models\ServerMetrics\Dto\ServerMetricRawSnapshot;

/**
 * Read-only query interface for server metrics.
 *
 * Defines contract for fetching historical metrics data,
 * enabling both database-backed and mock implementations for charts.
 */
interface ServerMetricsQueryInterface
{
    /**
     * Fetch hourly metric aggregates for a time range.
     *
     * @param string            $serverName Server identifier
     * @param DateTimeImmutable $from       Start of time range (inclusive)
     * @param DateTimeImmutable $to         End of time range (inclusive)
     *
     * @return array<int,ServerMetricHourlyAggregate> Array of hourly aggregates sorted by timestamp
     */
    public function getHourlyMetrics(
        string $serverName,
        DateTimeImmutable $from,
        DateTimeImmutable $to
    ): array;

    /**
     * Fetch daily metric aggregates for a date range.
     *
     * @param string            $serverName Server identifier
     * @param DateTimeImmutable $from       Start of date range (inclusive)
     * @param DateTimeImmutable $to         End of date range (inclusive)
     *
     * @return array<int,ServerMetricDailyAggregate> Array of daily aggregates sorted by date
     */
    public function getDailyMetrics(
        string $serverName,
        DateTimeImmutable $from,
        DateTimeImmutable $to
    ): array;

    /**
     * Fetch raw metric snapshots for a time range.
     *
     * @param string            $serverName Server identifier
     * @param DateTimeImmutable $from       Start of time range (inclusive)
     * @param DateTimeImmutable $to         End of time range (inclusive)
     * @param int               $limit      Maximum number of records to return (0 = no limit)
     *
     * @return array<int,ServerMetricRawSnapshot> Array of raw snapshots sorted by collected_at
     */
    public function getRawMetrics(
        string $serverName,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        int $limit = 0
    ): array;

    /**
     * Get available server names that have metrics data.
     *
     * @return array<int,string> List of server names
     */
    public function getAvailableServers(): array;
}
