<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring\Loader;

use Mata\Config\Monitoring\Section\ChartsConfig;
use Mata\Services\ServerMetrics\MetricCatalog;
use Mata\Services\ServerMetrics\MetricQueryPolicy;
use RuntimeException;

/** Loads optional chart-surface configuration. */
final class ChartsConfigLoader extends AbstractJsonConfigLoader
{
    public function __construct(
        private MetricCatalog $metricCatalog,
        private MetricQueryPolicy $queryPolicy,
        private bool $fallbackOnFailure = true,
    ) {}

    /** @return ConfigLoadResult<ChartsConfig> */
    public function load(string $configRoot): ConfigLoadResult
    {
        try {
            return $this->loadChartsConfig(rtrim($configRoot, '/') . '/charts.json');
        } catch (MonitoringConfigLoadException $exception) {
            if (!$this->fallbackOnFailure) {
                throw $exception;
            }

            return new ConfigLoadResult($this->getDefaultConfig(), $exception->problems);
        }
    }

    /** @return ConfigLoadResult<ChartsConfig> */
    public function loadChartsConfig(string $filePath): ConfigLoadResult
    {
        if (!is_file($filePath)) {
            return $this->result($this->getDefaultConfig());
        }

        try {
            $raw = $this->normalizeJson($this->readJsonObject($filePath));
        } catch (RuntimeException $exception) {
            $this->reportLoadFailure($filePath, $exception->getMessage());

            throw $this->rejected('charts.json invalid', $exception);
        }

        return $this->result(ChartsConfig::fromArray(
            $raw,
            $this->metricCatalog,
            $this->queryPolicy,
            $this->reportInvalidEntry(...),
        ));
    }

    public function getDefaultConfig(): ChartsConfig
    {
        return ChartsConfig::empty();
    }

    public function key(): string
    {
        return 'charts';
    }

    private function reportLoadFailure(string $filePath, string $reason): void
    {
        $this->report(
            'charts_config_invalid',
            "Could not load {$filePath}; falling back to chart defaults: {$reason}",
        );
    }

    private function reportInvalidEntry(string $message): void
    {
        $this->report('charts_config_invalid_entry', $message);
    }
}
