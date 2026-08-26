<?php

declare(strict_types=1);

namespace Mata\Services\ServerMetrics;

/** Metric series and granularities supported by server-metrics queries. */
final class MetricCatalog
{
    /** @var list<string> */
    private const METRICS = [
        'cpu',
        'ram_percent',
        'ram_used',
        'disk_percent',
        'disk_used',
        'file_count',
        'process_count',
    ];

    /** @var list<string> */
    private const GRANULARITIES = ['auto', 'raw', 'hourly', 'daily'];

    /** @return list<string> */
    public function metrics(): array
    {
        return self::METRICS;
    }

    /** @return list<string> */
    public function granularities(): array
    {
        return self::GRANULARITIES;
    }

    public function supports(string $metric): bool
    {
        return in_array($metric, self::METRICS, true);
    }

    public function allowsGranularity(string $granularity): bool
    {
        return in_array($granularity, self::GRANULARITIES, true);
    }
}
