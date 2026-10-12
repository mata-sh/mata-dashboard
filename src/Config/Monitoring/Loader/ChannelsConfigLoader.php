<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring\Loader;

use Mata\Config\Env;
use Mata\Config\Monitoring\Section\ChannelsConfig;
use RuntimeException;

/**
 * Loads the optional channels.json. A missing file means email-only delivery; a malformed
 * or unreadable file warns and yields no webhook channels. It never stops the boot.
 */
final class ChannelsConfigLoader extends AbstractJsonConfigLoader
{
    /** @return ConfigLoadResult<ChannelsConfig> */
    public function load(string $configRoot): ConfigLoadResult
    {
        $channelsFile = rtrim($configRoot, '/') . '/channels.json';
        if (!is_file($channelsFile)) {
            return $this->resultWithProblems(ChannelsConfig::empty());
        }

        try {
            $payload = $this->normalizeJson($this->readJsonObject($channelsFile));
        } catch (RuntimeException $exception) {
            $this->report(
                'channels_config_invalid',
                "Could not load {$channelsFile}; only email is available: {$exception->getMessage()}",
            );

            return $this->resultWithProblems(ChannelsConfig::empty());
        }

        $reportInvalidEntry = fn (string $message) => $this->report('channels_config_invalid_entry', $message);
        $config = ChannelsConfig::fromArray($payload, Env::get(...), $reportInvalidEntry);

        return $this->resultWithProblems($config);
    }
}
