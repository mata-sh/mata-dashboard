<?php

declare(strict_types=1);

namespace Mata\Services\Metrics;

/**
 * Single source of truth for the metric series the dashboard can query.
 *
 * Owns the vocabulary shared by request validation, the metrics controller,
 * and chart view building: which metric keys exist and which granularities
 * may be requested for them. Display labels stay with the renderers, which
 * word them differently per surface.
 */
final class MetricCatalog
{
    /** @var string[] */
    private const METRICS = [
        'cpu',
        'ram_percent',
        'ram_used',
        'disk_percent',
        'disk_used',
        'file_count',
        'process_count',
    ];

    /** @var string[] */
    private const GRANULARITIES = ['auto', 'raw', 'hourly', 'daily'];

    /**
     * Metric keys available for querying.
     *
     * @return string[]
     */
    public function metrics(): array
    {
        return self::METRICS;
    }

    /**
     * Granularities accepted on metrics requests, including `auto`.
     *
     * @return string[]
     */
    public function granularities(): array
    {
        return self::GRANULARITIES;
    }

    /** Whether a normalized metric key is part of the catalog. */
    public function supports(string $metric): bool
    {
        return in_array($metric, self::METRICS, true);
    }

    /** Whether a granularity may be requested. */
    public function allowsGranularity(string $granularity): bool
    {
        return in_array($granularity, self::GRANULARITIES, true);
    }
}
