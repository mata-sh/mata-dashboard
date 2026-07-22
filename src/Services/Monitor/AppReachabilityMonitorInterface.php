<?php

declare(strict_types=1);

namespace Mata\Services\Monitor;

use Mata\Constants\CacheTypes;
use Mata\Services\Monitor\Dto\EndpointTarget;

/**
 * Contract for monitoring application endpoint reachability.
 *
 * Provides methods to probe single or multiple application endpoints
 * with optional cache and timeout configuration.
 */
interface AppReachabilityMonitorInterface
{
    /**
     * Check reachability for a single application endpoint.
     *
     * @param EndpointTarget $target       target metadata for the endpoint to probe
     * @param null|int       $timeout      timeout in seconds; null uses the configured probe policy timeout
     * @param string         $cacheType    cache key for TTL lookup (default: CacheTypes::PING)
     * @param bool           $forceRefresh bypass a cached result while preserving its cache key
     *
     * @return array<string,mixed> result data (reachable,httpCode,latency_ms,error)
     */
    public function checkEndpoint(
        EndpointTarget $target,
        ?int $timeout = null,
        string $cacheType = CacheTypes::PING,
        bool $forceRefresh = false,
    ): array;

    /**
     * Check reachability for multiple application endpoints.
     *
     * @param array<int|string, EndpointTarget> $targets      endpoint targets keyed by identifier
     * @param null|int                          $timeout      timeout in seconds; null uses the configured probe policy timeout
     * @param string                            $cacheType    cache key for TTL lookup (default: CacheTypes::PING)
     * @param bool                              $forceRefresh bypass cached results while preserving their cache keys
     *
     * @return array<int|string,array<string,mixed>> results keyed by identifier
     */
    public function checkMultipleEndpoints(
        array $targets,
        ?int $timeout = null,
        string $cacheType = CacheTypes::PING,
        bool $forceRefresh = false,
    ): array;
}
