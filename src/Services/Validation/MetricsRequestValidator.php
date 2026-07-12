<?php

declare(strict_types=1);

namespace Mata\Services\Validation;

use InvalidArgumentException;

/**
 * Validates metrics API request parameters.
 *
 * Centralized validation logic for server, metric, range, and granularity
 * parameters. Extracts validation from controller for better testability
 * and reusability.
 */
class MetricsRequestValidator
{
    /**
     * @param string[] $supportedMetrics     List of valid metric keys
     * @param string[] $allowedGranularities List of valid granularity values
     */
    public function __construct(
        private array $supportedMetrics,
        private array $allowedGranularities
    ) {}

    /**
     * Validate all metrics request filters.
     *
     * @param string              $server        Server name
     * @param string              $metric        Metric key (raw input)
     * @param string              $range         Time range (e.g., "24h", "7d")
     * @param string              $granularity   Granularity (e.g., "auto", "hourly")
     * @param array<string,mixed> $serversConfig Server configuration array
     *
     * @return MetricsValidationResult Validation result with errors and normalized metric
     */
    public function validate(
        string $server,
        string $metric,
        string $range,
        string $granularity,
        array $serversConfig
    ): MetricsValidationResult {
        $normalizedMetric = $this->normalizeMetric($metric);
        $errors = [];

        if ($serversConfig !== [] && !isset($serversConfig[$server])) {
            $errors['server'] = sprintf("Unknown server '%s'", $server);
        }

        if (!in_array($normalizedMetric, $this->supportedMetrics, true)) {
            $errors['metric'] = sprintf("Unsupported metric '%s'", $metric);
        }

        if (!in_array($granularity, $this->allowedGranularities, true)) {
            $errors['granularity'] = sprintf("Invalid granularity '%s'", $granularity);
        }

        if ($range !== '' && !in_array($range, ['all', 'max'], true) && $this->parseRange($range) === null) {
            $errors['range'] = sprintf("Invalid range '%s'", $range);
        }

        return new MetricsValidationResult($errors, $normalizedMetric);
    }

    /**
     * Normalize metric input (lowercase, replace hyphens with underscores).
     *
     * @param string $metric Raw metric input
     *
     * @return string Normalized metric key
     */
    public function normalizeMetric(string $metric): string
    {
        return str_replace('-', '_', strtolower(trim($metric)));
    }

    /**
     * Parse time range string into value and unit.
     *
     * Supported formats: "24h", "7d", "4w", "3m", "1y"
     *
     * @param string $range Time range string
     *
     * @return null|array{value:int,unit:string} Parsed range or null if invalid
     */
    public function parseRange(string $range): ?array
    {
        if (!preg_match('/^(\d+)([hdwmy])$/', $range, $matches)) {
            return null;
        }

        $value = (int) $matches[1];
        if ($value <= 0) {
            return null;
        }

        return [
            'value' => $value,
            'unit' => $matches[2],
        ];
    }

    /**
     * Resolve appropriate granularity based on time range.
     *
     * Automatically selects optimal granularity for data aggregation:
     * - <= 6 hours: raw (5-minute intervals)
     * - <= 7 days: hourly aggregation
     * - > 7 days: daily aggregation
     *
     * @param string $range                Time range (e.g., "24h", "7d", "all")
     * @param string $requestedGranularity User-requested granularity or "auto"
     *
     * @return string Resolved granularity (raw, hourly, daily)
     *
     * @throws InvalidArgumentException If range is invalid when auto-resolving
     */
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

        $value = $parsed['value'];
        $unit = $parsed['unit'];

        return match ($unit) {
            'h' => $value <= 6 ? 'raw' : 'hourly',
            'd' => $value <= 7 ? 'hourly' : 'daily',
            'w' => $value <= 1 ? 'hourly' : 'daily',
            'm' => 'daily',
            'y' => 'daily',
            default => 'daily',
        };
    }
}
