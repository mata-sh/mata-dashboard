<?php

declare(strict_types=1);

namespace Mata\Services\Monitor;

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
    public function check(string $serverName, string $cacheType = CacheTypes::HEALTH): array;
}
