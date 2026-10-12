<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring\Section;

use InvalidArgumentException;
use Mata\Config\ConfigValueValidator;

/** Global, channel, and per-server alarm delivery limits. */
final readonly class AlarmRateLimitsConfig
{
    /**
     * @param array<string,AlarmRateLimit>               $channelLimits
     * @param array<string,array<string,AlarmRateLimit>> $serverOverrides
     */
    public function __construct(
        private AlarmRateLimit $globalDefault,
        private array $channelLimits,
        private array $serverOverrides,
    ) {}

    /**
     * Invalid channel and server entries are reported and skipped independently.
     *
     * @param array<string,mixed>   $raw
     * @param callable(string):void $reportInvalid
     */
    public static function fromArray(array $raw, callable $reportInvalid): self
    {
        $channels = $raw['channels'] ?? [];
        if (!ConfigValueValidator::isObject($channels)) {
            $reportInvalid('limits.channels must be an object; using default limits.');
            $channels = [];
        }

        $globalDefault = AlarmRateLimit::default();
        if (array_key_exists('default', $channels)) {
            $default = $channels['default'];
            if (!ConfigValueValidator::isObject($default)) {
                $reportInvalid('limits.channels.default must be an object; using default limits.');
            } else {
                try {
                    $globalDefault = AlarmRateLimit::fromArray($default, $globalDefault, 'limits.channels.default');
                } catch (InvalidArgumentException $exception) {
                    $reportInvalid($exception->getMessage() . ' Using default limits.');
                }
            }
        }

        $channelLimits = [];
        foreach ($channels as $channel => $config) {
            if ($channel === 'default') {
                continue;
            }
            $channel = strtolower((string) $channel);
            if (!ConfigValueValidator::isObject($config)) {
                $reportInvalid("limits.channels.{$channel} must be an object; skipping entry.");

                continue;
            }

            try {
                $channelLimits[$channel] = AlarmRateLimit::fromArray(
                    $config,
                    $globalDefault,
                    "limits.channels.{$channel}",
                );
            } catch (InvalidArgumentException $exception) {
                $reportInvalid($exception->getMessage() . ' Skipping entry.');
            }
        }

        $serverOverrides = [];
        $overrides = $raw['overrides'] ?? [];
        if (!ConfigValueValidator::isObject($overrides)) {
            $reportInvalid('limits.overrides must be an object; skipping overrides.');
            $overrides = [];
        }
        foreach ($overrides as $server => $serverConfig) {
            $server = strtolower((string) $server);
            if (!ConfigValueValidator::isObject($serverConfig)) {
                $reportInvalid("limits.overrides.{$server} must be an object; skipping entry.");

                continue;
            }
            foreach ($serverConfig as $channel => $override) {
                $channel = strtolower((string) $channel);
                if (!ConfigValueValidator::isObject($override)) {
                    $reportInvalid("limits.overrides.{$server}.{$channel} must be an object; skipping entry.");

                    continue;
                }

                try {
                    $serverOverrides[$server][$channel] = AlarmRateLimit::fromArray(
                        $override,
                        $channelLimits[$channel] ?? $globalDefault,
                        "limits.overrides.{$server}.{$channel}",
                    );
                } catch (InvalidArgumentException $exception) {
                    $reportInvalid($exception->getMessage() . ' Skipping entry.');
                }
            }
        }

        return new self($globalDefault, $channelLimits, $serverOverrides);
    }

    public static function default(): self
    {
        return self::fromArray([], static function (string $message): void {});
    }

    public function resolve(string $server, string $channel): AlarmRateLimit
    {
        $channelKey = strtolower($channel);
        $serverKey = strtolower($server);

        return $this->serverOverrides[$serverKey][$channelKey]
            ?? $this->channelLimits[$channelKey]
            ?? $this->globalDefault;
    }

    /** @return array<string,AlarmRateLimit> */
    public function getChannelLimits(): array
    {
        return $this->channelLimits;
    }

    /** @return array<string,array<string,AlarmRateLimit>> */
    public function getServerOverrides(): array
    {
        return $this->serverOverrides;
    }

    public function getGlobalDefault(): AlarmRateLimit
    {
        return $this->globalDefault;
    }
}
