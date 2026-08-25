<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring\Loader;

use InvalidArgumentException;
use Mata\Config\Monitoring\Section\DataRetentionConfig;
use Mata\Services\Event\EventRecorderService;
use RuntimeException;

final class DataRetentionConfigLoader extends AbstractJsonConfigLoader
{
    public function __construct(
        private EventRecorderService $eventRecorder,
        private bool $fallbackOnFailure = true,
    ) {}

    public function load(string $configRoot): DataRetentionConfig
    {
        try {
            return $this->loadDataRetentionConfig(rtrim($configRoot, '/') . '/data_retention.json');
        } catch (MonitoringConfigLoadException $exception) {
            if (!$this->fallbackOnFailure) {
                throw $exception;
            }

            return $this->getDefaultConfig();
        }
    }

    public function loadDataRetentionConfig(string $filePath): DataRetentionConfig
    {
        try {
            $config = $this->normalizeJson($this->readJsonObject($filePath));
        } catch (RuntimeException $exception) {
            $this->reportLoadFailure($filePath);

            throw new MonitoringConfigLoadException('data_retention.json invalid or empty', previous: $exception);
        }
        if ($config === []) {
            $this->reportLoadFailure($filePath);

            throw new MonitoringConfigLoadException('data_retention.json invalid or empty');
        }

        try {
            return DataRetentionConfig::fromArray($config);
        } catch (InvalidArgumentException $exception) {
            $this->reportLoadFailure($filePath, $exception->getMessage());

            throw new MonitoringConfigLoadException('data_retention.json invalid or empty', previous: $exception);
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
        $this->eventRecorder->recordEvent(
            type: 'data_retention_config_not_found',
            message: $message,
            level: 'warning',
        );
    }
}
