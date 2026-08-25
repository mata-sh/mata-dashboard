<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring\Loader;

use InvalidArgumentException;
use Mata\Config\Monitoring\Section\IntervalsConfig;
use Mata\Services\Event\EventRecorderService;
use RuntimeException;

final class IntervalsConfigLoader extends AbstractJsonConfigLoader
{
    public function __construct(
        private EventRecorderService $eventRecorder,
        private bool $fallbackOnFailure = true,
    ) {}

    public function load(string $configRoot): IntervalsConfig
    {
        try {
            return $this->loadIntervalsConfig(rtrim($configRoot, '/') . '/intervals.json');
        } catch (MonitoringConfigLoadException $exception) {
            if (!$this->fallbackOnFailure) {
                throw $exception;
            }

            return $this->getDefaultConfig();
        }
    }

    public function loadIntervalsConfig(string $filePath): IntervalsConfig
    {
        try {
            $config = $this->normalizeJson($this->readJsonObject($filePath));
        } catch (RuntimeException $exception) {
            $this->reportLoadFailure($filePath);

            throw new MonitoringConfigLoadException('intervals.json invalid or empty', previous: $exception);
        }
        if ($config === []) {
            $this->reportLoadFailure($filePath);

            throw new MonitoringConfigLoadException('intervals.json invalid or empty');
        }

        try {
            return IntervalsConfig::fromArray($config);
        } catch (InvalidArgumentException $exception) {
            $this->reportLoadFailure($filePath, $exception->getMessage());

            throw new MonitoringConfigLoadException('intervals.json invalid or empty', previous: $exception);
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
        $this->eventRecorder->recordEvent(
            type: 'intervals_config_not_found',
            message: $message,
            level: 'warning',
        );
    }
}
