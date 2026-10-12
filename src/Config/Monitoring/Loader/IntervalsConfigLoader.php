<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring\Loader;

use InvalidArgumentException;
use Mata\Config\Monitoring\Section\IntervalsConfig;
use RuntimeException;

final class IntervalsConfigLoader extends AbstractJsonConfigLoader
{
    public function __construct(
        private bool $fallbackOnFailure = true,
    ) {}

    /** @return ConfigLoadResult<IntervalsConfig> */
    public function load(string $configRoot): ConfigLoadResult
    {
        try {
            return $this->loadIntervalsConfig(rtrim($configRoot, '/') . '/intervals.json');
        } catch (MonitoringConfigLoadException $exception) {
            if (!$this->fallbackOnFailure) {
                throw $exception;
            }

            return new ConfigLoadResult($this->getDefaultConfig(), $exception->problems);
        }
    }

    /** @return ConfigLoadResult<IntervalsConfig> */
    public function loadIntervalsConfig(string $filePath): ConfigLoadResult
    {
        try {
            $config = $this->normalizeJson($this->readJsonObject($filePath));
        } catch (RuntimeException $exception) {
            $this->reportLoadFailure($filePath);

            throw $this->rejected('intervals.json invalid or empty', $exception);
        }
        if ($config === []) {
            $this->reportLoadFailure($filePath);

            throw $this->rejected('intervals.json invalid or empty');
        }

        try {
            return $this->result(IntervalsConfig::fromArray($config));
        } catch (InvalidArgumentException $exception) {
            $this->reportLoadFailure($filePath, $exception->getMessage());

            throw $this->rejected('intervals.json invalid or empty', $exception);
        }
    }

    public function getDefaultConfig(): IntervalsConfig
    {
        return IntervalsConfig::default();
    }

    private function reportLoadFailure(string $filePath, ?string $reason = null): void
    {
        $message = "Could not load {$filePath} -> fallback to Default Values";
        if ($reason !== null) {
            $message .= " (reason: {$reason})";
        }
        $this->report('intervals_config_not_found', $message);
    }
}
