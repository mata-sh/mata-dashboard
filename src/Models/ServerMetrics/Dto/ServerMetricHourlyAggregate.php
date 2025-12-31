<?php

declare(strict_types=1);

namespace Watcher\Models\ServerMetrics\Dto;

use DateTimeImmutable;

/**
 * Immutable DTO encapsulating pre-aggregated hourly metrics for a server.
 */
final class ServerMetricHourlyAggregate
{
    public function __construct(
        public readonly DateTimeImmutable $hourTimestamp,
        public readonly ?float $cpuAvg,
        public readonly ?float $cpuMin,
        public readonly ?float $cpuMax,
        public readonly ?int $ramUsedAvg,
        public readonly ?int $ramUsedMin,
        public readonly ?int $ramUsedMax,
        public readonly ?float $ramPercentAvg,
        public readonly ?float $ramPercentMax,
        public readonly ?int $diskUsedAvg,
        public readonly ?int $diskUsedMin,
        public readonly ?int $diskUsedMax,
        public readonly ?float $diskPercentAvg,
        public readonly ?float $diskPercentMax,
        public readonly ?int $fileCountAvg,
        public readonly ?int $processCountAvg,
        public readonly ?int $processCountMax,
        public readonly int $sampleCount,
        public readonly ?DateTimeImmutable $firstSample,
        public readonly ?DateTimeImmutable $lastSample
    ) {}
}
