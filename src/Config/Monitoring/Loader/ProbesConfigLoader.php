<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring\Loader;

use Mata\Config\Monitoring\Section\ProbesConfig;

/**
 * Loads the optional probes.json: node probe timeout and retry overrides.
 *
 * @extends AbstractJsonConfigLoader<ProbesConfig>
 */
final class ProbesConfigLoader extends AbstractJsonConfigLoader
{
    public const PROBES_FILE = 'probes.json';

    protected function fileName(): string
    {
        return self::PROBES_FILE;
    }

    protected function loadFailureType(): string
    {
        return 'probes_config_invalid';
    }

    protected function isOptional(): bool
    {
        return true;
    }

    protected function defaultSection(): ProbesConfig
    {
        return ProbesConfig::default();
    }

    protected function parse(array $config, string $filePath): ProbesConfig
    {
        return ProbesConfig::fromArray($config);
    }
}
