<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring\Loader;

use InvalidArgumentException;
use Mata\Config\Monitoring\Section\ProbesConfig;
use Mata\Services\Event\EventRecorderService;
use RuntimeException;

final class ProbesConfigLoader extends AbstractJsonConfigLoader
{
    public function __construct(
        private EventRecorderService $eventRecorder,
        private bool $fallbackOnFailure = true,
    ) {}

    public function load(string $configRoot): ProbesConfig
    {
        try {
            return $this->loadProbesConfig(rtrim($configRoot, '/') . '/probes.json');
        } catch (MonitoringConfigLoadException $exception) {
            if (!$this->fallbackOnFailure) {
                throw $exception;
            }

            return $this->getDefaultConfig();
        }
    }

    public function loadProbesConfig(string $filePath): ProbesConfig
    {
        try {
            $config = $this->normalizeJson($this->readJsonObject($filePath));
        } catch (RuntimeException $exception) {
            $this->reportLoadFailure($filePath);

            throw new MonitoringConfigLoadException('probes.json invalid or empty', previous: $exception);
        }
        if ($config === []) {
            $this->reportLoadFailure($filePath);

            throw new MonitoringConfigLoadException('probes.json invalid or empty');
        }

        try {
            return ProbesConfig::fromArray($config);
        } catch (InvalidArgumentException $exception) {
            $this->reportLoadFailure($filePath, $exception->getMessage());

            throw new MonitoringConfigLoadException('probes.json invalid or empty', previous: $exception);
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
        $this->eventRecorder->recordEvent(
            type: 'probes_config_not_found',
            message: $message,
            level: 'warning',
        );
    }
}
