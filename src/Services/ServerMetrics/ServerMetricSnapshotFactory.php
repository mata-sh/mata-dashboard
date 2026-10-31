<?php

declare(strict_types=1);

namespace Mata\Services\ServerMetrics;

use DateTimeImmutable;
use DateTimeZone;
use Mata\Models\ServerMetrics\Dto\ServerMetricRawSnapshot;
use Mata\Services\ServerMetrics\Dto\CollectorIdentity;
use Mata\Utils\TypeConverter;

/**
 * Turns one server health payload, as {@see \Mata\Services\Monitor\ServerMonitor::collectServerHealthData()}
 * returns it, into the raw sample the metrics pipeline stores.
 *
 * The node's server/data response is normalized before it gets here, so the usage keys are
 * always present. php_version, os_version and uptime are optional: demo data has them, the
 * node does not.
 */
final readonly class ServerMetricSnapshotFactory
{
    private const FALLBACK_COLLECTOR_NAME = 'collect_server_metrics_cron';
    private const NODE_COLLECTOR_NAME = 'mata-node';

    /**
     * @param array<string,mixed>    $payload
     * @param null|DateTimeImmutable $now     Ingestion time; defaults to now in UTC
     */
    public function fromHealthPayload(array $payload, ?DateTimeImmutable $now = null): ServerMetricRawSnapshot
    {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $cache = is_array($payload['cache'] ?? null) ? $payload['cache'] : [];
        $processes = is_array($data['processes'] ?? null) ? $data['processes'] : [];

        $ramUsed = TypeConverter::toInt($data['ram_used'] ?? null);
        $ramTotal = TypeConverter::toInt($data['ram_capacity'] ?? null);
        $diskUsed = TypeConverter::toInt($data['disk_space_used'] ?? null);
        $diskTotal = TypeConverter::toInt($data['disk_space_capacity'] ?? null);

        return new ServerMetricRawSnapshot(
            collectedAt: $this->collectedAt($data, $cache, $now),
            ingestedAt: $now,
            cpuUsage: TypeConverter::toFloat($data['cpu_usage'] ?? null),
            ramUsed: $ramUsed,
            ramTotal: $ramTotal,
            diskUsed: $diskUsed,
            diskTotal: $diskTotal,
            ramUsagePercent: $this->percent($ramUsed, $ramTotal),
            diskUsagePercent: $this->percent($diskUsed, $diskTotal),
            totalFileCount: TypeConverter::toInt($data['total_system_filecount'] ?? null),
            processCount: TypeConverter::toInt($processes['total'] ?? null),
            phpProcessCount: TypeConverter::toInt($processes['php'] ?? null),
            phpVersion: TypeConverter::toString($data['php_version'] ?? null),
            osVersion: TypeConverter::toString($data['os_version'] ?? null),
            serverUptime: TypeConverter::toInt($data['server_uptime'] ?? $data['uptime'] ?? null),
        );
    }

    /**
     * The payload's collector block when the node sends one, otherwise the collector fields of
     * the server's configuration, and finally this cron job itself.
     *
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $serverConfig
     */
    public function collectorIdentity(array $payload, array $serverConfig): CollectorIdentity
    {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $collector = $data['collector'] ?? null;
        if (is_array($collector)) {
            $name = TypeConverter::toString($collector['name'] ?? null);

            return new CollectorIdentity(
                $name === null || $name === '' ? self::NODE_COLLECTOR_NAME : $name,
                TypeConverter::toString($collector['version'] ?? null),
                TypeConverter::toString($collector['contact'] ?? null),
            );
        }

        return new CollectorIdentity(
            TypeConverter::toString($serverConfig['collector_name'] ?? null)
                ?? TypeConverter::toString($serverConfig['node_name'] ?? null)
                ?? self::FALLBACK_COLLECTOR_NAME,
            TypeConverter::toString($serverConfig['collector_version'] ?? null),
            TypeConverter::toString($serverConfig['collector_contact'] ?? null),
        );
    }

    /**
     * The node does not stamp server/data, so a payload served from the cache is dated by its age.
     *
     * @param array<string,mixed> $data
     * @param array<string,mixed> $cache
     */
    private function collectedAt(array $data, array $cache, DateTimeImmutable $now): DateTimeImmutable
    {
        $stamped = TypeConverter::toDateTime($data['collected_at'] ?? null);
        if ($stamped !== null) {
            return $stamped->setTimezone(new DateTimeZone('UTC'));
        }

        $cacheAge = TypeConverter::toInt($cache['age'] ?? null) ?? 0;

        return $cacheAge > 0 ? $now->modify("-{$cacheAge} seconds") : $now;
    }

    private function percent(?int $used, ?int $total): ?float
    {
        if ($used === null || $total === null || $total <= 0) {
            return null;
        }

        return round($used / $total * 100, 2);
    }
}
