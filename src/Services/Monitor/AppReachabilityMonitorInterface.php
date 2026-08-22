<?php

declare(strict_types=1);

namespace Mata\Services\Monitor;

use Mata\Constants\CacheType;
use Mata\Services\Monitor\Dto\EndpointProbeResult;
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
     * @param CacheType      $cacheType    cache key for TTL lookup (default: CacheType::Ping)
     * @param bool           $forceRefresh bypass a cached result while preserving its cache key
     */
    public function checkEndpoint(
        EndpointTarget $target,
        ?int $timeout = null,
        CacheType $cacheType = CacheType::Ping,
        bool $forceRefresh = false,
    ): EndpointProbeResult;

    /**
     * Check reachability for multiple application endpoints.
     *
     * @param array<int|string, EndpointTarget> $targets      endpoint targets keyed by identifier
     * @param null|int                          $timeout      timeout in seconds; null uses the configured probe policy timeout
     * @param CacheType                         $cacheType    cache key for TTL lookup (default: CacheType::Ping)
     * @param bool                              $forceRefresh bypass cached results while preserving their cache keys
     *
     * @return array<int|string,EndpointProbeResult> results keyed by identifier
     */
    public function checkMultipleEndpoints(
        array $targets,
        ?int $timeout = null,
        CacheType $cacheType = CacheType::Ping,
        bool $forceRefresh = false,
    ): array;
}
