<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring\Loader;

use Mata\Config\Monitoring\Section\IntervalsConfig;

/**
 * Loads intervals.json: event cooldowns and delivery deduplication windows.
 *
 * @extends AbstractJsonConfigLoader<IntervalsConfig>
 */
final class IntervalsConfigLoader extends AbstractJsonConfigLoader
{
    public const INTERVALS_FILE = 'intervals.json';

    protected function fileName(): string
    {
        return self::INTERVALS_FILE;
    }

    protected function problemType(): string
    {
        return 'intervals_config_invalid';
    }

    protected function defaultSection(): IntervalsConfig
    {
        return IntervalsConfig::default();
    }

    protected function parse(array $config, string $filePath): IntervalsConfig
    {
        return IntervalsConfig::fromArray($config);
    }
}
