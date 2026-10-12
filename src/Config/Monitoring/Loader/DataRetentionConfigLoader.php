<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring\Loader;

use InvalidArgumentException;
use Mata\Config\Monitoring\Loader\Dto\ConfigLoadResult;
use Mata\Config\Monitoring\Section\DataRetentionConfig;
use RuntimeException;

final class DataRetentionConfigLoader extends AbstractJsonConfigLoader
{
    public function __construct(
        private bool $fallbackOnFailure = true,
    ) {}

    /** @return ConfigLoadResult<DataRetentionConfig> */
    public function load(string $configRoot): ConfigLoadResult
    {
        try {
            return $this->loadDataRetentionConfig(rtrim($configRoot, '/') . '/data_retention.json');
        } catch (MonitoringConfigLoadException $exception) {
            if (!$this->fallbackOnFailure) {
                throw $exception;
            }

            return new ConfigLoadResult($this->getDefaultConfig(), $exception->problems);
        }
    }

    /** @return ConfigLoadResult<DataRetentionConfig> */
    public function loadDataRetentionConfig(string $filePath): ConfigLoadResult
    {
        try {
            $config = $this->readJsonArray($filePath);
        } catch (RuntimeException $exception) {
            $this->reportLoadFailure($filePath);

            throw $this->rejected('data_retention.json invalid or empty', $exception);
        }
        if ($config === []) {
            $this->reportLoadFailure($filePath);

            throw $this->rejected('data_retention.json invalid or empty');
        }

        try {
            return $this->result(DataRetentionConfig::fromArray($config));
        } catch (InvalidArgumentException $exception) {
            $this->reportLoadFailure($filePath, $exception->getMessage());

            throw $this->rejected('data_retention.json invalid or empty', $exception);
        }
    }

    public function getDefaultConfig(): DataRetentionConfig
    {
        return DataRetentionConfig::default();
    }

    private function reportLoadFailure(string $filePath, ?string $reason = null): void
    {
        $message = "Could not load {$filePath} -> fallback to Default Values";
        if ($reason !== null) {
            $message .= " (reason: {$reason})";
        }
        $this->report('data_retention_config_not_found', $message);
    }
}
