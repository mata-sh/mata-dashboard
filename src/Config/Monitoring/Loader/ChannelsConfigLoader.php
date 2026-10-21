<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring\Loader;

use Mata\Config\Env;
use Mata\Config\Monitoring\Section\ChannelsConfig;

/**
 * Loads the optional channels.json: alarm channels besides email.
 * Without it, alarms are delivered by email only.
 *
 * @extends AbstractJsonConfigLoader<ChannelsConfig>
 */
final class ChannelsConfigLoader extends AbstractJsonConfigLoader
{
    public const CHANNELS_FILE = 'channels.json';

    protected function fileName(): string
    {
        return self::CHANNELS_FILE;
    }

    protected function loadFailureType(): string
    {
        return 'channels_config_invalid';
    }

    protected function isOptional(): bool
    {
        return true;
    }

    protected function defaultSection(): ChannelsConfig
    {
        return ChannelsConfig::empty();
    }

    protected function parse(array $config, string $filePath): ChannelsConfig
    {
        return ChannelsConfig::fromArray($config, Env::get(...), $this->reportInvalidEntry(...));
    }
}
