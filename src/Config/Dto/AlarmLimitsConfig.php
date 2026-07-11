<?php

declare(strict_types=1);

namespace Mata\Config\Dto;

use Mata\Services\Alarm\Dto\AlarmLimit;

/**
 * Aggregates channel-level defaults and per-server overrides.
 *
 * Provides resolution helpers to compute the effective limit for a
 * `(server, channel)` pair using the cascading fallback:
 * server override → channel default → global default.
 */
final class AlarmLimitsConfig
{
    /** @var array<string,AlarmLimit> */
    private array $channelLimits;

    /** @var array<string,array<string,AlarmLimit>> */
    private array $serverOverrides;

    private AlarmLimit $globalDefault;

    /**
     * @param AlarmLimit                             $globalDefault   Global fallback limits
     * @param array<string,AlarmLimit>               $channelLimits   Limits keyed by channel name
     * @param array<string,array<string,AlarmLimit>> $serverOverrides Overrides keyed by server name, then channel
     */
    public function __construct(AlarmLimit $globalDefault, array $channelLimits, array $serverOverrides = [])
    {
        $this->globalDefault = $globalDefault;
        $this->channelLimits = $channelLimits;
        $this->serverOverrides = $serverOverrides;
    }

    /**
     * Resolve limit for the given server/channel combination.
     */
    public function resolve(string $server, string $channel): AlarmLimit
    {
        $channelKey = strtolower($channel);
        $serverKey = strtolower($server);

        if ($serverKey !== '' && isset($this->serverOverrides[$serverKey][$channelKey])) {
            return $this->serverOverrides[$serverKey][$channelKey];
        }

        if (isset($this->channelLimits[$channelKey])) {
            return $this->channelLimits[$channelKey];
        }

        return $this->globalDefault;
    }

    /**
     * Return all configured channels.
     *
     * @return array<string,AlarmLimit>
     */
    public function getChannelLimits(): array
    {
        return $this->channelLimits;
    }

    /**
     * Return per-server overrides.
     *
     * @return array<string,array<string,AlarmLimit>>
     */
    public function getServerOverrides(): array
    {
        return $this->serverOverrides;
    }

    public function getGlobalDefault(): AlarmLimit
    {
        return $this->globalDefault;
    }
}
