<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring\Loader;

use Mata\Config\Monitoring\Section\ThresholdsConfig;
use Mata\Services\Alarm\AlarmDirectProcessor;
use Mata\Services\Alarm\Dto\Alarm;
use Mata\Services\Alarm\Dto\AlarmLevel;
use Mata\Services\Event\EventRecorderService;
use RuntimeException;

final class ThresholdsConfigLoader extends AbstractJsonConfigLoader
{
    public function __construct(
        private EventRecorderService $eventRecorder,
        private ?AlarmDirectProcessor $alarmDirectProcessor = null,
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
            $this->recordConfigurationEvent('thresholds_config_validation_error', $message, 'warning');
        });
    }

    public function getDefaultConfig(): ThresholdsConfig
    {
        return ThresholdsConfig::default();
    }

    private function reportLoadFailure(string $filePath, RuntimeException $exception): void
    {
        $this->recordConfigurationEvent(
            type: 'thresholds_config_not_found',
            message: "Could not load {$filePath} -> no Server Watching (JSON error: {$exception->getMessage()})",
            level: 'critical',
            forceAlarm: true,
        );
    }

    private function recordConfigurationEvent(string $type, string $message, string $level = 'info', bool $forceAlarm = false): void
    {
        $recordResult = $this->eventRecorder->recordEvent(type: $type, message: $message, level: $level);
        if ($forceAlarm && $this->alarmDirectProcessor !== null) {
            $this->alarmDirectProcessor->process(new Alarm(
                type: $type,
                message: $message,
                level: AlarmLevel::fromString($level),
                server: $recordResult->serverLabel,
                app: $recordResult->appLabel,
                channels: ['email'],
            ), $recordResult);
        }
    }
}
