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
    protected function fileName(): string
    {
        return 'channels.json';
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
