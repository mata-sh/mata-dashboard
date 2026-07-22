<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring;

final class MonitoringConfigManager
{
    /** @param iterable<MonitoringConfigLoaderInterface> $loaders */
    public function __construct(private iterable $loaders) {}

    /** @return array<string,mixed> */
    public function loadAllConfigs(string $configPath, string $appEnvironment = 'prod'): array
    {
        $config = [];
        foreach ($this->loaders as $loader) {
            try {
                $config[$loader->key()] = $loader->load($configPath);
            } catch (MonitoringConfigLoadException $exception) {
                if (!$loader->shouldFallback()) {
                    throw $exception;
                }
                $config[$loader->key()] = $loader->getDefaultConfig();
            }
        }
        $config['environment'] = $appEnvironment;
        $config['charts'] = [];

        return $config;
    }
}
