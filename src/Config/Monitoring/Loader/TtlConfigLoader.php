<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring\Loader;

use InvalidArgumentException;
use Mata\Config\Monitoring\Section\TtlConfig;
use Mata\Services\Event\EventManager;
use RuntimeException;

final class TtlConfigLoader extends AbstractJsonConfigLoader
{
    public function __construct(
        private EventManager $events,
        private bool $fallbackOnFailure = true,
    ) {}

    public function load(string $configRoot): TtlConfig
    {
        try {
            return $this->loadTtlConfig(rtrim($configRoot, '/') . '/ttl.json');
        } catch (MonitoringConfigLoadException $exception) {
            if (!$this->fallbackOnFailure) {
                throw $exception;
            }

            return $this->getDefaultConfig();
        }
    }

    public function loadTtlConfig(string $filePath): TtlConfig
    {
        try {
            $config = $this->normalizeJson($this->readJsonObject($filePath));
            if ($config === []) {
                throw new RuntimeException('ttl.json invalid or empty');
            }

            return TtlConfig::fromArray($config);
        } catch (InvalidArgumentException|RuntimeException $exception) {
            $this->reportLoadFailure($filePath, $exception->getMessage());

            throw new MonitoringConfigLoadException('ttl.json invalid or empty', previous: $exception);
        }
    }

    public function getDefaultConfig(): TtlConfig
    {
        return TtlConfig::default();
    }

    private function reportLoadFailure(string $filePath, string $reason): void
    {
        $this->reportProblem(
            $this->events,
            'ttl_config_not_found',
            "Could not load {$filePath} -> fallback to Default Values (reason: {$reason})",
        );
    }
}
