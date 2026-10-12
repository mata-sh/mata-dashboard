<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring\Loader;

use Mata\Config\Monitoring\Loader\Dto\ConfigLoadResult;
use Mata\Config\Monitoring\Section\ThresholdsConfig;
use RuntimeException;

final class ThresholdsConfigLoader extends AbstractJsonConfigLoader
{
    public function __construct(
        private bool $fallbackOnFailure = true,
    ) {}

    /** @return ConfigLoadResult<ThresholdsConfig> */
    public function load(string $configRoot): ConfigLoadResult
    {
        try {
            return $this->loadThresholdsConfig(rtrim($configRoot, '/') . '/thresholds.json');
        } catch (MonitoringConfigLoadException $exception) {
            if (!$this->fallbackOnFailure) {
                throw $exception;
            }

            return new ConfigLoadResult($this->getDefaultConfig(), $exception->problems);
        }
    }

    /** @return ConfigLoadResult<ThresholdsConfig> */
    public function loadThresholdsConfig(string $filePath): ConfigLoadResult
    {
        try {
            $thresholds = $this->readJsonArray($filePath);
        } catch (RuntimeException $exception) {
            $this->reportLoadFailure($filePath, $exception);

            throw $this->rejected('thresholds.json invalid or empty', $exception);
        }
        if ($thresholds === []) {
            $exception = new RuntimeException('thresholds.json invalid or empty');
            $this->reportLoadFailure($filePath, $exception);

            throw $this->rejected('thresholds.json invalid or empty', $exception);
        }

        $reportInvalidEntry = fn (string $message) => $this->report('thresholds_config_validation_error', $message);
        $config = ThresholdsConfig::fromArray($thresholds, $reportInvalidEntry);

        return $this->result($config);
    }

    public function getDefaultConfig(): ThresholdsConfig
    {
        return ThresholdsConfig::default();
    }

    private function reportLoadFailure(string $filePath, RuntimeException $exception): void
    {
        $this->report(
            'thresholds_config_not_found',
            "Could not load {$filePath} -> fallback to default thresholds (reason: {$exception->getMessage()})",
        );
    }
}
