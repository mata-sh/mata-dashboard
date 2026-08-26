<?php

declare(strict_types=1);

namespace Mata\Services\ServerMetrics;

use DateInterval;
use Exception;
use InvalidArgumentException;

/** Shared normalization and range policy for server-metrics queries. */
class MetricQueryPolicy
{
    public function normalizeMetric(string $metric): string
    {
        return str_replace('-', '_', strtolower(trim($metric)));
    }

    /** @return null|array{value:int,unit:string} */
    public function parseRange(string $range): ?array
    {
        if (!preg_match('/^(\d+)([hdwmy])$/', $range, $matches)) {
            return null;
        }

        $value = (int) $matches[1];
        $unit = $matches[2];
        if ($value <= 0 || !$this->isDateIntervalRepresentable($value, $unit)) {
            return null;
        }

        return ['value' => $value, 'unit' => $unit];
    }

    public function resolveGranularity(string $range, string $requestedGranularity): string
    {
        if ($requestedGranularity !== 'auto') {
            return $requestedGranularity;
        }

        if ($range === 'all' || $range === 'max') {
            return 'daily';
        }

        $parsed = $this->parseRange($range);
        if ($parsed === null) {
            throw new InvalidArgumentException('Unable to determine granularity for invalid range.');
        }

        return match ($parsed['unit']) {
            'h' => $parsed['value'] <= 6 ? 'raw' : 'hourly',
            'd' => $parsed['value'] <= 7 ? 'hourly' : 'daily',
            'w' => $parsed['value'] <= 1 ? 'hourly' : 'daily',
            'm', 'y' => 'daily',
            default => 'daily',
        };
    }

    private function isDateIntervalRepresentable(int $value, string $unit): bool
    {
        $specification = match ($unit) {
            'h' => "PT{$value}H",
            'd' => "P{$value}D",
            'w' => "P{$value}W",
            'm' => "P{$value}M",
            'y' => "P{$value}Y",
            default => '',
        };

        try {
            new DateInterval($specification);
        } catch (Exception) {
            return false;
        }

        return true;
    }
}
