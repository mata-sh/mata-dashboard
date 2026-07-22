<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring;

use Mata\Config\Dto\AlarmLimitsConfig;
use Mata\Services\Alarm\Dto\AlarmLimit;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

final class AlarmLimitsConfigLoader extends AbstractJsonConfigLoader
{
    private LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    public function load(string $limitsFile): AlarmLimitsConfig
    {
        $payload = $this->decodeFile($limitsFile);
        $channels = $payload['channels'] ?? [];
        $globalDefault = AlarmLimit::fromArray(
            $channels['default'] ?? [],
            new AlarmLimit(AlarmLimit::DEFAULT_PER_MINUTE, AlarmLimit::DEFAULT_PER_HOUR, AlarmLimit::DEFAULT_PER_DAY),
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
            $channelLimits[strtolower((string) $channel)] = AlarmLimit::fromArray($channelConfig, $globalDefault);
        }

        $serverOverrides = [];
        $overridePayload = $payload['overrides'] ?? [];
        if (is_array($overridePayload)) {
            foreach ($overridePayload as $serverKey => $serverConfig) {
                $serverKeyLower = strtolower((string) $serverKey);
                if (!is_array($serverConfig)) {
                    $this->logger->warning("Skipping malformed server limits override [server={$serverKey}, reason='override must be an object']");

                    continue;
                }
                foreach ($serverConfig as $channelKey => $channelOverride) {
                    if (!is_array($channelOverride)) {
                        $this->logger->warning("Skipping malformed channel override [server={$serverKey}, channel={$channelKey}]");

                        continue;
                    }
                    $channelLower = strtolower((string) $channelKey);
                    $serverOverrides[$serverKeyLower][$channelLower] = AlarmLimit::fromArray(
                        $channelOverride,
                        $channelLimits[$channelLower] ?? $globalDefault,
                    );
                }
            }
        }

        return new AlarmLimitsConfig($globalDefault, $channelLimits, $serverOverrides);
    }

    /** @return array<string,mixed> */
    private function decodeFile(string $limitsFile): array
    {
        if ($limitsFile === '' || !is_file($limitsFile)) {
            $this->logger->warning("Alarm limits file missing; falling back to defaults [file={$limitsFile}]");

            return [];
        }
        if (!is_readable($limitsFile)) {
            $this->logger->warning("Alarm limits file unreadable; falling back to defaults [file={$limitsFile}]");

            return [];
        }

        try {
            return $this->normalizeJson($this->readJsonObject($limitsFile));
        } catch (RuntimeException $exception) {
            $this->logger->warning("Alarm limits file malformed; falling back to defaults [file={$limitsFile}, error={$exception->getMessage()}]");

            return [];
        }
    }
}
