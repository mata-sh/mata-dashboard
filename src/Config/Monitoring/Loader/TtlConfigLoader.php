<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring\Loader;

use InvalidArgumentException;
use Mata\Config\Monitoring\Section\TtlConfig;
use RuntimeException;

final class TtlConfigLoader extends AbstractJsonConfigLoader
{
    public function __construct(
        private bool $fallbackOnFailure = true,
    ) {}

    /** @return ConfigLoadResult<TtlConfig> */
    public function load(string $configRoot): ConfigLoadResult
    {
        try {
            return $this->loadTtlConfig(rtrim($configRoot, '/') . '/ttl.json');
        } catch (MonitoringConfigLoadException $exception) {
            if (!$this->fallbackOnFailure) {
                throw $exception;
            }

            return new ConfigLoadResult($this->getDefaultConfig(), $exception->problems);
        }
    }

    /** @return ConfigLoadResult<TtlConfig> */
    public function loadTtlConfig(string $filePath): ConfigLoadResult
    {
        try {
            $config = $this->normalizeJson($this->readJsonObject($filePath));
            if ($config === []) {
                throw new RuntimeException('ttl.json invalid or empty');
            }

            return $this->resultWithProblems(TtlConfig::fromArray($config));
        } catch (InvalidArgumentException|RuntimeException $exception) {
            $this->reportLoadFailure($filePath, $exception->getMessage());

            throw $this->rejected('ttl.json invalid or empty', $exception);
        }
    }

    public function getDefaultConfig(): TtlConfig
    {
        return TtlConfig::default();
    }

    private function reportLoadFailure(string $filePath, string $reason): void
    {
        $this->report(
            'ttl_config_not_found',
            "Could not load {$filePath} -> fallback to Default Values (reason: {$reason})",
        );
    }
}
