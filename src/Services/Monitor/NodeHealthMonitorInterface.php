<?php

declare(strict_types=1);

namespace Mata\Services\Monitor;

use GuzzleHttp\Promise\PromiseInterface;
use Mata\Constants\CacheTypes;

/**
 * Contract for node health endpoint monitoring.
 */
interface NodeHealthMonitorInterface
{
    /**
     * Check a node's health endpoint.
     *
     * @return array<string,mixed>
     */
    public function checkOrWait(
        string $serverName,
        string $cacheType = CacheTypes::HEALTH,
        bool $forceRefresh = false,
    ): array;

    /**
     * Return cached health immediately or a cache-miss probe promise.
     *
     * @return array<string,mixed>|PromiseInterface
     */
    public function checkAsync(
        string $serverName,
        string $cacheType = CacheTypes::HEALTH,
        bool $forceRefresh = false,
    ): array|PromiseInterface;
}
