<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring\Loader;

use Mata\Config\Monitoring\Section\ChartsConfig;
use Mata\Services\ServerMetrics\MetricCatalog;
use Mata\Services\ServerMetrics\MetricQueryPolicy;

/**
 * Loads the optional charts.json: dashboard chart metrics and timespans.
 *
 * @extends AbstractJsonConfigLoader<ChartsConfig>
 */
final class ChartsConfigLoader extends AbstractJsonConfigLoader
{
    public const CHARTS_FILE = 'charts.json';

    public function __construct(
        private MetricCatalog $metricCatalog,
        private MetricQueryPolicy $queryPolicy,
        bool $fallbackOnFailure = true,
    ) {
        parent::__construct($fallbackOnFailure);
    }

    protected function fileName(): string
    {
        return self::CHARTS_FILE;
    }

    protected function problemType(): string
    {
        return 'charts_config_invalid';
    }

    protected function isOptional(): bool
    {
        return true;
    }

    protected function defaultSection(): ChartsConfig
    {
        return ChartsConfig::empty();
    }

    protected function parse(array $config, string $filePath): ChartsConfig
    {
        return ChartsConfig::fromArray($config, $this->metricCatalog, $this->queryPolicy, $this->reportInvalidEntry(...));
    }
}
