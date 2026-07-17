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
     * @param EndpointTarget $target       target metadata for the endpoint to probe
     * @param int            $timeout      timeout in seconds (default: 2)
     * @param string         $cacheType    cache key for TTL lookup (default: CacheTypes::PING)
     * @param bool           $forceRefresh bypass a cached result while preserving its cache key
     *
     * @return array<string,mixed> result data (reachable,httpCode,latency_ms,error)
     */
    public function checkEndpoint(
        EndpointTarget $target,
        int $timeout = 2,
        string $cacheType = CacheTypes::PING,
        bool $forceRefresh = false,
    ): array;

    /**
     * Check availability for multiple application endpoints.
     *
     * @param array<int|string, EndpointTarget> $targets      endpoint targets keyed by identifier
     * @param int                               $timeout      timeout in seconds (default: 2)
     * @param string                            $cacheType    cache key for TTL lookup (default: CacheTypes::PING)
     * @param bool                              $forceRefresh bypass cached results while preserving their cache keys
     *
     * @return array<int|string,array<string,mixed>> results keyed by identifier
     */
    public function checkMultipleEndpoints(
        array $targets,
        int $timeout = 2,
        string $cacheType = CacheTypes::PING,
        bool $forceRefresh = false,
    ): array;
}
