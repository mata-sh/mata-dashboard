<?php

declare(strict_types=1);

namespace Mata\Services\Monitor;

use GuzzleHttp\Promise\PromiseInterface;
use Mata\Constants\CacheType;

/**
 * Contract for node health endpoint monitoring.
 */
interface NodeHealthMonitorInterface
{
    /**
     * Check a node's health endpoint and wait for a cache-miss probe to finish.
     *
     * @return array<string,mixed>
     */
    public function checkSynchronously(
        string $serverName,
        CacheType $cacheTtlType = CacheType::Health,
        bool $forceRefresh = false,
    ): array;

    /**
     * Return cached health immediately or a cache-miss probe promise.
     *
     * @return array<string,mixed>|PromiseInterface
     */
    public function checkAsync(
        string $serverName,
        CacheType $cacheTtlType = CacheType::Health,
        bool $forceRefresh = false,
    ): array|PromiseInterface;
}
