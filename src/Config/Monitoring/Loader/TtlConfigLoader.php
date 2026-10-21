<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring\Loader;

use Mata\Config\Monitoring\Section\TtlConfig;

/**
 * Loads ttl.json: cache TTLs in seconds, per cache type.
 *
 * @extends AbstractJsonConfigLoader<TtlConfig>
 */
final class TtlConfigLoader extends AbstractJsonConfigLoader
{
    public const TTL_FILE = 'ttl.json';

    protected function fileName(): string
    {
        return self::TTL_FILE;
    }

    protected function loadFailureType(): string
    {
        return 'ttl_config_invalid';
    }

    protected function defaultSection(): TtlConfig
    {
        return TtlConfig::default();
    }

    protected function parse(array $config, string $filePath): TtlConfig
    {
        return TtlConfig::fromArray($config);
    }
}
