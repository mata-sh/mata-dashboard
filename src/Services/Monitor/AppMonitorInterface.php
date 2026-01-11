<?php

declare(strict_types=1);

namespace Mata\Services\Monitor;

use Mata\Constants\CacheTypes;
use Mata\Services\Monitor\Dto\EndpointTarget;

/**
 * Contract for monitoring application availability and health metrics.
 *
 * Provides methods to check single or multiple application endpoints
 * with optional cache and timeout configuration.
 */
interface AppMonitorInterface
{
    /**
     * Check availability for a single application endpoint.
     *
     * @param EndpointTarget $target    target metadata for the endpoint to probe
     * @param int            $timeout   timeout in seconds (default: 2)
     * @param string         $cacheType cache key for TTL lookup (default: CacheTypes::PING)
     *
     * @return array<string,mixed> result data (reachable,httpCode,latency_ms,error)
     */
    public function checkEndpoint(EndpointTarget $target, int $timeout = 2, string $cacheType = CacheTypes::PING): array;

    /**
     * Check availability for multiple application endpoints.
     *
     * @param array<int|string, EndpointTarget> $targets   endpoint targets keyed by identifier
     * @param int                               $timeout   timeout in seconds (default: 2)
     * @param string                            $cacheType cache key for TTL lookup (default: CacheTypes::PING)
     * @param bool                              $async     whether asynchronous execution is requested (default: false)
     *
     * @return array<string,array<string,mixed>> results keyed by identifier
     */
    public function checkMultipleEndpoints(array $targets, int $timeout = 2, string $cacheType = CacheTypes::PING, bool $async = false): array;
}
