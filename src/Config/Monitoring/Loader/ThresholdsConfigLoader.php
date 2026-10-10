<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring\Loader;

use Mata\Config\Monitoring\Section\ThresholdsConfig;
use Mata\Services\Event\EventManager;
use RuntimeException;

final class ThresholdsConfigLoader extends AbstractJsonConfigLoader
{
    public function __construct(
        private EventManager $events,
        private bool $fallbackOnFailure = true,
    ) {}

    public function load(string $configRoot): ThresholdsConfig
    {
        try {
            return $this->loadThresholdsConfig(rtrim($configRoot, '/') . '/thresholds.json');
        } catch (MonitoringConfigLoadException $exception) {
            if (!$this->fallbackOnFailure) {
                throw $exception;
            }

            return $this->getDefaultConfig();
        }
    }

    public function loadThresholdsConfig(string $filePath): ThresholdsConfig
    {
        try {
            $thresholds = $this->normalizeJson($this->readJsonObject($filePath));
        } catch (RuntimeException $exception) {
            $this->reportLoadFailure($filePath, $exception);

            throw new MonitoringConfigLoadException('thresholds.json invalid or empty', previous: $exception);
        }
        if ($thresholds === []) {
            $exception = new RuntimeException('thresholds.json invalid or empty');
            $this->reportLoadFailure($filePath, $exception);

            throw new MonitoringConfigLoadException('thresholds.json invalid or empty', previous: $exception);
        }

        return ThresholdsConfig::fromArray($thresholds, function (string $message): void {
            $this->reportProblem($this->events, 'thresholds_config_validation_error', $message);
        });
    }

    public function getDefaultConfig(): ThresholdsConfig
    {
        return ThresholdsConfig::default();
    }

    private function reportLoadFailure(string $filePath, RuntimeException $exception): void
    {
        $this->reportProblem(
            $this->events,
            'thresholds_config_not_found',
            "Could not load {$filePath} -> fallback to default thresholds (reason: {$exception->getMessage()})",
        );
    }
}
