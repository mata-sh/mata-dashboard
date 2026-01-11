<?php

declare(strict_types=1);

namespace Mata\Config\Dto;

use Mata\Services\Alert\Dto\AlertLimit;

/**
 * Aggregates channel-level defaults and per-server overrides.
 *
 * Provides resolution helpers to compute the effective limit for a
 * `(server, channel)` pair using the cascading fallback:
 * server override → channel default → global default.
 */
final class AlertLimitsConfig
{
    /** @var array<string,AlertLimit> */
    private array $channelLimits;

    /** @var array<string,array<string,AlertLimit>> */
    private array $serverOverrides;

    private AlertLimit $globalDefault;

    /**
     * @param AlertLimit                             $globalDefault   Global fallback limits
     * @param array<string,AlertLimit>               $channelLimits   Limits keyed by channel name
     * @param array<string,array<string,AlertLimit>> $serverOverrides Overrides keyed by server name, then channel
     */
    public function __construct(AlertLimit $globalDefault, array $channelLimits, array $serverOverrides = [])
    {
        $this->globalDefault = $globalDefault;
        $this->channelLimits = $channelLimits;
        $this->serverOverrides = $serverOverrides;
    }

    /**
     * Resolve limit for the given server/channel combination.
     */
    public function resolve(string $server, string $channel): AlertLimit
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
     * @return array<string,AlertLimit>
     */
    public function getChannelLimits(): array
    {
        return $this->channelLimits;
    }

    /**
     * Return per-server overrides.
     *
     * @return array<string,array<string,AlertLimit>>
     */
    public function getServerOverrides(): array
    {
        return $this->serverOverrides;
    }

    public function getGlobalDefault(): AlertLimit
    {
        return $this->globalDefault;
    }
}
