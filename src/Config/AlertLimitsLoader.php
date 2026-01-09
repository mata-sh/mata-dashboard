<?php

declare(strict_types=1);

namespace Watcher\Config;

use JsonException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Watcher\Config\Dto\AlertLimitsConfig;
use Watcher\Services\Foundation\Dto\AlertLimit;

/**
 * Loader for alert throttling configuration stored in limits.json.
 *
 * Understands the cascading precedence described in ALARM_LIMITING.md:
 * server override → channel default → global default.
 */
final class AlertLimitsLoader
{
    private LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Load alert limits configuration.
     *
     * Server overrides for unknown servers are loaded but validation happens at runtime
     * when alerts are actually dispatched, allowing for flexible configuration.
     *
     * @param string $limitsFile Absolute path to limits.json
     */
    public function load(string $limitsFile): AlertLimitsConfig
    {
        $payload = $this->decodeFile($limitsFile);

        $channels = $payload['channels'] ?? [];
        $globalDefault = AlertLimit::fromArray(
            $channels['default'] ?? [],
            new AlertLimit(
                AlertLimit::DEFAULT_PER_MINUTE,
                AlertLimit::DEFAULT_PER_HOUR,
                AlertLimit::DEFAULT_PER_DAY
            )
        );

        $channelLimits = [];
        foreach ($channels as $channel => $channelConfig) {
            if ($channel === 'default') {
                continue;
            }
            if (!is_array($channelConfig)) {
                $this->logger->warning("Skipping invalid channel limit configuration [channel={$channel}, reason='not an array']");

                continue;
            }

            $channelLimits[strtolower($channel)] = AlertLimit::fromArray($channelConfig, $globalDefault);
        }

        $serverOverrides = [];
        $overridePayload = $payload['overrides'] ?? [];
        if (is_array($overridePayload)) {
            foreach ($overridePayload as $serverKey => $serverConfig) {
                $serverKeyLower = strtolower((string) $serverKey);

                if (!is_array($serverConfig)) {
                    $this->logger->warning(
                        "Skipping malformed server limits override [server={$serverKey}, reason='override must be an object']"
                    );

                    continue;
                }

                foreach ($serverConfig as $channelKey => $channelOverride) {
                    if (!is_array($channelOverride)) {
                        $this->logger->warning(
                            "Skipping malformed channel override [server={$serverKey}, channel={$channelKey}]"
                        );

                        continue;
                    }

                    $channelLower = strtolower((string) $channelKey);
                    $serverOverrides[$serverKeyLower][$channelLower] = AlertLimit::fromArray(
                        $channelOverride,
                        $channelLimits[$channelLower] ?? $globalDefault
                    );
                }
            }
        }

        return new AlertLimitsConfig($globalDefault, $channelLimits, $serverOverrides);
    }

    /**
     * Decode configuration file, returning empty array on error.
     *
     * @return array<string,mixed>
     */
    private function decodeFile(string $limitsFile): array
    {
        if ($limitsFile === '' || !is_file($limitsFile)) {
            $this->logger->warning("Alert limits file missing; falling back to defaults [file={$limitsFile}]");

            return [];
        }

        $contents = file_get_contents($limitsFile);
        if ($contents === false) {
            $this->logger->warning("Alert limits file unreadable; falling back to defaults [file={$limitsFile}]");

            return [];
        }

        try {
            $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->logger->warning(
                "Alert limits file malformed; falling back to defaults [file={$limitsFile}, error={$exception->getMessage()}]"
            );

            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }
}
