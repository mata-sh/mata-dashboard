<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring\Loader;

use InvalidArgumentException;
use Mata\Config\Monitoring\Section\ProbesConfig;
use RuntimeException;

/** Loads optional node probe overrides; a missing probes.json means built-in defaults. */
final class ProbesConfigLoader extends AbstractJsonConfigLoader
{
    public function __construct(
        private bool $fallbackOnFailure = true,
    ) {}

    /** @return ConfigLoadResult<ProbesConfig> */
    public function load(string $configRoot): ConfigLoadResult
    {
        try {
            return $this->loadProbesConfig(rtrim($configRoot, '/') . '/probes.json');
        } catch (MonitoringConfigLoadException $exception) {
            if (!$this->fallbackOnFailure) {
                throw $exception;
            }

            return new ConfigLoadResult($this->getDefaultConfig(), $exception->problems);
        }
    }

    /** @return ConfigLoadResult<ProbesConfig> */
    public function loadProbesConfig(string $filePath): ConfigLoadResult
    {
        if (!is_file($filePath)) {
            return $this->resultWithProblems($this->getDefaultConfig());
        }

        try {
            $config = $this->normalizeJson($this->readJsonObject($filePath));
        } catch (RuntimeException $exception) {
            $this->reportLoadFailure($filePath);

            throw $this->rejected('probes.json invalid or empty', $exception);
        }
        if ($config === []) {
            $this->reportLoadFailure($filePath);

            throw $this->rejected('probes.json invalid or empty');
        }

        try {
            return $this->resultWithProblems(ProbesConfig::fromArray($config));
        } catch (InvalidArgumentException $exception) {
            $this->reportLoadFailure($filePath, $exception->getMessage());

            throw $this->rejected('probes.json invalid or empty', $exception);
        }
    }

    public function getDefaultConfig(): ProbesConfig
    {
        return ProbesConfig::default();
    }

    private function reportLoadFailure(string $filePath, ?string $reason = null): void
    {
        $message = "Could not load {$filePath} -> fallback to Default Values";
        if ($reason !== null) {
            $message .= " (reason: {$reason})";
        }
        $this->report('probes_config_not_found', $message);
    }
}
