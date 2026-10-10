<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring\Loader;

use Mata\Config\Env;
use Mata\Config\Monitoring\Section\ChannelsConfig;
use Mata\Services\Event\EventManager;
use RuntimeException;

/**
 * Loads the optional channels.json. A missing file means email-only delivery; a malformed
 * or unreadable file warns and yields no webhook channels. It never stops the boot.
 */
final class ChannelsConfigLoader extends AbstractJsonConfigLoader
{
    public function __construct(private EventManager $events) {}

    public function load(string $configRoot): ChannelsConfig
    {
        $channelsFile = rtrim($configRoot, '/') . '/channels.json';
        if (!is_file($channelsFile)) {
            return ChannelsConfig::empty();
        }

        try {
            $payload = $this->normalizeJson($this->readJsonObject($channelsFile));
        } catch (RuntimeException $exception) {
            $this->reportProblem(
                $this->events,
                'channels_config_invalid',
                "Could not load {$channelsFile}; only email is available: {$exception->getMessage()}",
            );

            return ChannelsConfig::empty();
        }

        return ChannelsConfig::fromArray($payload, Env::get(...), function (string $message): void {
            $this->reportProblem($this->events, 'channels_config_invalid_entry', $message);
        });
    }
}
