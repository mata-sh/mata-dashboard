<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring\Loader;

use Mata\Config\Monitoring\Section\ChartsConfig;
use Mata\Services\Event\EventRecorderService;
use Mata\Services\ServerMetrics\MetricCatalog;
use Mata\Services\ServerMetrics\MetricQueryPolicy;
use RuntimeException;

/** Loads optional chart-surface configuration. */
final class ChartsConfigLoader extends AbstractJsonConfigLoader
{
    public function __construct(
        private EventRecorderService $eventRecorder,
        private MetricCatalog $metricCatalog,
        private MetricQueryPolicy $queryPolicy,
        private bool $fallbackOnFailure = true,
    ) {}

    public function load(string $configRoot): ChartsConfig
    {
        try {
            return $this->loadChartsConfig(rtrim($configRoot, '/') . '/charts.json');
        } catch (MonitoringConfigLoadException $exception) {
            if (!$this->fallbackOnFailure) {
                throw $exception;
            }

            return $this->getDefaultConfig();
        }
    }

    public function loadChartsConfig(string $filePath): ChartsConfig
    {
        if (!is_file($filePath)) {
            return $this->getDefaultConfig();
        }

        try {
            $raw = $this->normalizeJson($this->readJsonObject($filePath));
        } catch (RuntimeException $exception) {
            $this->reportLoadFailure($filePath, $exception->getMessage());

            throw new MonitoringConfigLoadException('charts.json invalid', previous: $exception);
        }

        return ChartsConfig::fromArray(
            $raw,
            $this->metricCatalog,
            $this->queryPolicy,
            $this->reportInvalidEntry(...),
        );
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
        $this->eventRecorder->recordEvent(
            type: 'charts_config_invalid',
            message: "Could not load {$filePath}; falling back to chart defaults: {$reason}",
            level: 'warning',
        );
    }

    private function reportInvalidEntry(string $message): void
    {
        $this->eventRecorder->recordEvent(
            type: 'charts_config_invalid_entry',
            message: $message,
            level: 'warning',
        );
    }
}
