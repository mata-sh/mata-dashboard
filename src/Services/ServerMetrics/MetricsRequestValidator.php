<?php

declare(strict_types=1);

namespace Mata\Services\ServerMetrics;

use Mata\Services\ServerMetrics\Dto\MetricsValidationResult;

/** Validates HTTP query inputs for server-metrics endpoints. */
class MetricsRequestValidator
{
    public function __construct(
        private MetricCatalog $catalog,
        private MetricQueryPolicy $queryPolicy,
    ) {}

    /**
     * @param array<string,mixed> $serversConfig
     */
    public function validate(
        string $server,
        string $metric,
        string $range,
        string $granularity,
        array $serversConfig,
    ): MetricsValidationResult {
        $normalizedMetric = $this->queryPolicy->normalizeMetric($metric);
        $errors = [];

        if ($serversConfig !== [] && !isset($serversConfig[$server])) {
            $errors['server'] = sprintf("Unknown server '%s'", $server);
        }
        if (!$this->catalog->supports($normalizedMetric)) {
            $errors['metric'] = sprintf("Unsupported metric '%s'", $metric);
        }
        if (!$this->catalog->allowsGranularity($granularity)) {
            $errors['granularity'] = sprintf("Invalid granularity '%s'", $granularity);
        }
        if ($range !== ''
            && !in_array($range, ['all', 'max'], true)
            && $this->queryPolicy->parseRange($range) === null) {
            $errors['range'] = sprintf("Invalid range '%s'", $range);
        }

        return new MetricsValidationResult($errors, $normalizedMetric);
    }
}
