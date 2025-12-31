<?php

declare(strict_types=1);

namespace Watcher\Models\ServerMetrics\Dto;

use DateTimeImmutable;

/**
 * Immutable value object representing a raw server metric snapshot.
 *
 * Consumers should supply normalized, typed values so downstream persistence
 * code can rely on strict typing without additional defensive checks.
 */
final class ServerMetricRawSnapshot
{
    public function __construct(
        public readonly DateTimeImmutable $collectedAt,
        public readonly DateTimeImmutable $ingestedAt,
        public readonly ?float $cpuUsage,
        public readonly ?int $ramUsed,
        public readonly ?int $ramTotal,
        public readonly ?int $diskUsed,
        public readonly ?int $diskTotal,
        public readonly ?float $ramUsagePercent,
        public readonly ?float $diskUsagePercent,
        public readonly ?int $totalFileCount,
        public readonly ?int $processCount,
        public readonly ?int $phpProcessCount,
        public readonly ?string $phpVersion,
        public readonly ?string $osVersion,
        public readonly ?int $serverUptime
    ) {}
}
