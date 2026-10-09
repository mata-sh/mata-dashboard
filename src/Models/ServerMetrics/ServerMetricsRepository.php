<?php

declare(strict_types=1);

namespace Mata\Models\ServerMetrics;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use Mata\Database\DatabaseInterface;
use Mata\Models\ConfigSyncRepository;
use Mata\Models\ServerMetrics\Dto\ServerMetricDailyAggregate;
use Mata\Models\ServerMetrics\Dto\ServerMetricHourlyAggregate;
use Mata\Models\ServerMetrics\Dto\ServerMetricRawSnapshot;
use Mata\Services\Identity\ServerApplicationIdResolver;
use RuntimeException;
use Throwable;

/**
 * Persistence layer for server metric tables.
 *
 * Responsibilities:
 * - Write operations (insert/update) for raw, hourly, and daily metrics
 * - Resolving foreign key identifiers (servers, collectors)
 * - Normalizing inbound DTO data
 * - Computing payload hashes for deduplication
 * - Coordinating aggregate timestamp queries
 *
 * Note: For time-series read operations, use ServerMetricsQuery instead.
 * For chart/visualization data, use MetricsViewBuilder with ServerMetricsQuery.
 */
final class ServerMetricsRepository
{
    private DatabaseInterface $db;
    private ServerApplicationIdResolver $idResolver;
    private ConfigSyncRepository $configSyncRepository;

    /** @var array<string,int> */
    private array $collectorIdCache = [];

    public function __construct(
        DatabaseInterface $db,
        ServerApplicationIdResolver $idResolver,
        ConfigSyncRepository $configSyncRepository
    ) {
        $this->db = $db;
        $this->idResolver = $idResolver;
        $this->configSyncRepository = $configSyncRepository;
    }

    public function storeRawSample(
        string $serverName,
        string $collectorName,
        ServerMetricRawSnapshot $snapshot,
        ?string $collectorVersion = null,
        ?string $collectorContact = null
    ): void {
        $normalizedSnapshot = $this->normalizeSnapshot($snapshot);
        $serverId = $this->resolveServerId($serverName);
        $collectorId = $this->resolveCollectorId(
            $collectorName,
            $collectorVersion,
            $collectorContact
        );
        $payloadHash = $this->computePayloadHashHex($serverId, $collectorId, $normalizedSnapshot);

        ServerMetricRaw::fromSnapshot($serverId, $collectorId, $normalizedSnapshot, $payloadHash)
            ->insertOrUpdate($this->db)
        ;
    }

    public function upsertHourlyAggregate(
        string $serverName,
        ServerMetricHourlyAggregate $aggregate
    ): void {
        $normalizedAggregate = $this->normalizeHourlyAggregate($aggregate);
        $serverId = $this->resolveServerId($serverName);

        ServerMetricHourly::fromAggregate($serverId, $normalizedAggregate)
            ->insertOrUpdate($this->db)
        ;
    }

    public function upsertDailyAggregate(
        string $serverName,
        ServerMetricDailyAggregate $aggregate
    ): void {
        $normalizedAggregate = $this->normalizeDailyAggregate($aggregate);
        $serverId = $this->resolveServerId($serverName);

        ServerMetricDaily::fromAggregate($serverId, $normalizedAggregate)
            ->insertOrUpdate($this->db)
        ;
    }

    /**
     * Return total raw samples per server.
     *
     * @return array<string,int>
     */
    public function getRawSampleCountsByServer(): array
    {
        $rows = $this->db->runSelect(
            <<<'SQL'
                SELECT s.name AS server_name, COUNT(*) AS sample_count
                FROM server_metrics_raw AS r
                INNER JOIN servers AS s ON s.id = r.server_id
                GROUP BY s.name
            SQL
        );

        $counts = [];
        foreach ($rows as $row) {
            $name = isset($row['server_name']) ? (string) $row['server_name'] : '';
            if ($name === '') {
                continue;
            }

            $counts[$name] = (int) ($row['sample_count'] ?? 0);
        }

        return $counts;
    }

    /**
     * Return the last collected timestamp for each server.
     *
     * @return array<string,DateTimeImmutable>
     */
    public function getLatestRawSampleCollectedAt(): array
    {
        $rows = $this->db->runSelect(
            <<<'SQL'
                SELECT s.name AS server_name, MAX(r.collected_at) AS last_collected
                FROM server_metrics_raw AS r
                INNER JOIN servers AS s ON s.id = r.server_id
                GROUP BY s.name
            SQL
        );

        $timestamps = [];
        foreach ($rows as $row) {
            $name = isset($row['server_name']) ? (string) $row['server_name'] : '';
            $rawTimestamp = $row['last_collected'] ?? null;
            if ($name === '' || $rawTimestamp === null) {
                continue;
            }

            try {
                $timestamps[$name] = new DateTimeImmutable((string) $rawTimestamp, new DateTimeZone('UTC'));
            } catch (Throwable) {
                continue;
            }
        }

        return $timestamps;
    }

    /**
     * Fetch the most recent aggregate timestamps to coordinate incremental jobs.
     *
     * @return array{
     *     hourly: null|array{hour_timestamp: DateTimeImmutable, sample_count: int},
     *     daily: null|array{date: DateTimeImmutable, sample_count: int}
     * }
     */
    public function getLatestAggregates(string $serverName): array
    {
        $serverId = $this->resolveServerId($serverName);

        $latestHourly = $this->db->runSelect(
            <<<'SQL'
            SELECT hour_timestamp, sample_count 
            FROM server_metrics_hourly 
            WHERE server_id = ? 
            ORDER BY hour_timestamp DESC 
            LIMIT 1
            SQL,
            [$serverId]
        );

        $latestDaily = $this->db->runSelect(
            <<<'SQL'
            SELECT date, sample_count 
            FROM server_metrics_daily 
            WHERE server_id = ? 
            ORDER BY date DESC 
            LIMIT 1
            SQL,
            [$serverId]
        );

        return [
            'hourly' => $this->mapHourlyRow($latestHourly[0] ?? null),
            'daily' => $this->mapDailyRow($latestDaily[0] ?? null),
        ];
    }

    private function normalizeSnapshot(ServerMetricRawSnapshot $snapshot): ServerMetricRawSnapshot
    {
        return new ServerMetricRawSnapshot(
            $snapshot->collectedAt,
            $snapshot->ingestedAt,
            $this->normalizePercentage($snapshot->cpuUsage),
            $this->normalizeCount($snapshot->ramUsed),
            $this->normalizeCount($snapshot->ramTotal),
            $this->normalizeCount($snapshot->diskUsed),
            $this->normalizeCount($snapshot->diskTotal),
            $this->normalizePercentage($snapshot->ramUsagePercent),
            $this->normalizePercentage($snapshot->diskUsagePercent),
            $this->normalizeCount($snapshot->totalFileCount),
            $this->normalizeCount($snapshot->processCount),
            $this->normalizeCount($snapshot->phpProcessCount),
            $this->normalizeString($snapshot->phpVersion),
            $this->normalizeString($snapshot->osVersion),
            $this->normalizeCount($snapshot->serverUptime)
        );
    }

    private function normalizeHourlyAggregate(
        ServerMetricHourlyAggregate $aggregate
    ): ServerMetricHourlyAggregate {
        return new ServerMetricHourlyAggregate(
            $aggregate->hourTimestamp,
            $this->normalizePercentage($aggregate->cpuAvg),
            $this->normalizePercentage($aggregate->cpuMin),
            $this->normalizePercentage($aggregate->cpuMax),
            $this->normalizeCount($aggregate->ramUsedAvg),
            $this->normalizeCount($aggregate->ramUsedMin),
            $this->normalizeCount($aggregate->ramUsedMax),
            $this->normalizePercentage($aggregate->ramPercentAvg),
            $this->normalizePercentage($aggregate->ramPercentMax),
            $this->normalizeCount($aggregate->diskUsedAvg),
            $this->normalizeCount($aggregate->diskUsedMin),
            $this->normalizeCount($aggregate->diskUsedMax),
            $this->normalizePercentage($aggregate->diskPercentAvg),
            $this->normalizePercentage($aggregate->diskPercentMax),
            $this->normalizeCount($aggregate->fileCountAvg),
            $this->normalizeCount($aggregate->fileCountMax),
            $this->normalizeCount($aggregate->processCountAvg),
            $this->normalizeCount($aggregate->processCountMax),
            $this->normalizeSampleCount($aggregate->sampleCount),
            $aggregate->firstSample,
            $aggregate->lastSample
        );
    }

    private function normalizeDailyAggregate(
        ServerMetricDailyAggregate $aggregate
    ): ServerMetricDailyAggregate {
        return new ServerMetricDailyAggregate(
            $aggregate->date,
            $this->normalizePercentage($aggregate->cpuAvg),
            $this->normalizePercentage($aggregate->cpuMin),
            $this->normalizePercentage($aggregate->cpuMax),
            $this->normalizeCount($aggregate->ramUsedAvg),
            $this->normalizeCount($aggregate->ramUsedMin),
            $this->normalizeCount($aggregate->ramUsedMax),
            $this->normalizePercentage($aggregate->ramPercentAvg),
            $this->normalizePercentage($aggregate->ramPercentMax),
            $this->normalizeCount($aggregate->diskUsedAvg),
            $this->normalizeCount($aggregate->diskUsedMin),
            $this->normalizeCount($aggregate->diskUsedMax),
            $this->normalizePercentage($aggregate->diskPercentAvg),
            $this->normalizePercentage($aggregate->diskPercentMax),
            $this->normalizeCount($aggregate->fileCountAvg),
            $this->normalizeCount($aggregate->fileCountMax),
            $this->normalizeCount($aggregate->processCountAvg),
            $this->normalizeCount($aggregate->processCountMax),
            $this->normalizeSampleCount($aggregate->sampleCount),
            $this->normalizeSampleCount($aggregate->alarmCount),
            $this->normalizeSampleCount($aggregate->warningCount)
        );
    }

    private function normalizeSampleCount(int $value): int
    {
        return max(0, $value);
    }

    private function normalizeCount(?int $value): ?int
    {
        if ($value === null) {
            return null;
        }

        return max(0, $value);
    }

    private function normalizePercentage(?float $value): ?float
    {
        if ($value === null) {
            return null;
        }

        $clamped = min(100.0, max(0.0, $value));

        return round($clamped, 2);
    }

    private function normalizeString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function computePayloadHashHex(
        int $serverId,
        int $collectorId,
        ServerMetricRawSnapshot $snapshot
    ): string {
        $payload = [
            'server_id' => $serverId,
            'collector_id' => $collectorId,
            'collected_at' => $snapshot->collectedAt->format('Y-m-d H:i:s.u'),
            'cpu_usage' => $snapshot->cpuUsage,
            'ram_used' => $snapshot->ramUsed,
            'ram_total' => $snapshot->ramTotal,
            'disk_used' => $snapshot->diskUsed,
            'disk_total' => $snapshot->diskTotal,
            'ram_usage_percent' => $snapshot->ramUsagePercent,
            'disk_usage_percent' => $snapshot->diskUsagePercent,
            'total_file_count' => $snapshot->totalFileCount,
            'process_count' => $snapshot->processCount,
            'php_process_count' => $snapshot->phpProcessCount,
            'php_version' => $snapshot->phpVersion,
            'os_version' => $snapshot->osVersion,
            'server_uptime' => $snapshot->serverUptime,
        ];

        try {
            $encoded = json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION
            );
        } catch (JsonException $exception) {
            throw new RuntimeException('Failed to encode payload hash data.', 0, $exception);
        }

        return hash('md5', $encoded);
    }

    private function resolveServerId(string $serverName): int
    {
        // Try resolving via ServerApplicationIdResolver first (uses in-memory cache + database maps)
        $resolved = $this->idResolver->resolveServer($serverName);

        if ($resolved['id'] !== null) {
            return $resolved['id'];
        }

        // Server not found - create it via ConfigSyncRepository
        try {
            $this->configSyncRepository->upsertServer(
                $serverName,
                null, // hostname
                null, // provider
                '{}' // empty metadata JSON
            );

            // Refresh ServerApplicationIdResolver to pick up the newly created server
            $this->idResolver->refreshFromRepository();

            // Resolve again after creation
            $resolved = $this->idResolver->resolveServer($serverName);

            if ($resolved['id'] !== null) {
                return $resolved['id'];
            }
        } catch (Throwable) {
            // Handle race condition: another process may have created the server
            $this->idResolver->refreshFromRepository();
            $resolved = $this->idResolver->resolveServer($serverName);

            if ($resolved['id'] !== null) {
                return $resolved['id'];
            }
        }

        throw new RuntimeException(sprintf("Unable to resolve server_id for '%s'.", $serverName));
    }

    private function resolveCollectorId(
        string $collectorName,
        ?string $collectorVersion,
        ?string $collectorContact
    ): int {
        $cacheKey = strtolower($collectorName);
        if (isset($this->collectorIdCache[$cacheKey])) {
            return $this->collectorIdCache[$cacheKey];
        }

        $normalizedVersion = $this->normalizeString($collectorVersion);
        $normalizedContact = $this->normalizeString($collectorContact);

        $existing = $this->db->runSelect(
            'SELECT id, version, contact FROM metric_collectors WHERE name = ?',
            [$collectorName]
        );

        if (!empty($existing[0]['id'])) {
            $collectorId = (int) $existing[0]['id'];
            $currentVersion = $this->normalizeString($existing[0]['version'] ?? null);
            $currentContact = $this->normalizeString($existing[0]['contact'] ?? null);

            if ($currentVersion !== $normalizedVersion || $currentContact !== $normalizedContact) {
                $this->db->runStatement(
                    <<<'SQL'
                    UPDATE metric_collectors 
                    SET version = ?, contact = ?, updated_at = CURRENT_TIMESTAMP 
                    WHERE id = ?
                    SQL,
                    [$normalizedVersion, $normalizedContact, $collectorId]
                );
            }

            $this->collectorIdCache[$cacheKey] = $collectorId;

            return $collectorId;
        }

        try {
            $insertId = $this->db->runInsert(
                <<<'SQL'
                INSERT INTO metric_collectors 
                (name, version, contact, created_at, updated_at) 
                VALUES (?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                SQL,
                [$collectorName, $normalizedVersion, $normalizedContact]
            );

            if ($insertId > 0) {
                $this->collectorIdCache[$cacheKey] = $insertId;

                return $insertId;
            }
        } catch (Throwable) {
            // Likely duplicate insert due to race; continue to lookup below.
        }

        $existing = $this->db->runSelect(
            'SELECT id FROM metric_collectors WHERE name = ?',
            [$collectorName]
        );

        if (!empty($existing[0]['id'])) {
            $collectorId = (int) $existing[0]['id'];
            $this->collectorIdCache[$cacheKey] = $collectorId;

            return $collectorId;
        }

        throw new RuntimeException(sprintf("Unable to resolve collector_id for '%s'.", $collectorName));
    }

    /**
     * @param null|array<string,mixed> $row
     *
     * @return null|array{hour_timestamp: DateTimeImmutable, sample_count: int}
     */
    private function mapHourlyRow(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }

        try {
            $timestamp = new DateTimeImmutable((string) $row['hour_timestamp'], new DateTimeZone('UTC'));
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'Unable to parse hour_timestamp from persistence layer.',
                0,
                $exception
            );
        }

        return [
            'hour_timestamp' => $timestamp,
            'sample_count' => (int) $row['sample_count'],
        ];
    }

    /**
     * @param null|array<string,mixed> $row
     *
     * @return null|array{date: DateTimeImmutable, sample_count: int}
     */
    private function mapDailyRow(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }

        try {
            $date = new DateTimeImmutable((string) $row['date'], new DateTimeZone('UTC'));
        } catch (Throwable $exception) {
            throw new RuntimeException('Unable to parse date from persistence layer.', 0, $exception);
        }

        return [
            'date' => $date,
            'sample_count' => (int) $row['sample_count'],
        ];
    }
}
