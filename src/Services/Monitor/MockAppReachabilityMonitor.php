<?php

declare(strict_types=1);

namespace Mata\Services\Monitor;

use Mata\Services\Cache\CacheType;
use Mata\Services\Monitor\Dto\EndpointProbeResult;
use Mata\Services\Monitor\Dto\EndpointTarget;

/**
 * Demo implementation of the AppReachabilityMonitor: every endpoint is reachable,
 * with a stable per-URL latency.
 */
class MockAppReachabilityMonitor implements AppReachabilityMonitorInterface
{
    public function checkEndpoint(
        EndpointTarget $target,
        ?int $timeout = null,
        CacheType $cacheType = CacheType::Ping,
        bool $forceRefresh = false,
    ): EndpointProbeResult {
        return $this->generateMockResult($target->url);
    }

    /**
     * @param array<int|string,EndpointTarget> $targets
     *
     * @return array<int|string,EndpointProbeResult>
     */
    public function checkMultipleEndpoints(
        array $targets,
        ?int $timeout = null,
        CacheType $cacheType = CacheType::Ping,
        bool $forceRefresh = false,
    ): array {
        if ($targets === []) {
            return [];
        }

        $results = [];
        foreach ($targets as $identifier => $target) {
            $results[$identifier] = $this->generateMockResult($target->url);
        }

        return $results;
    }

    /** Produce a mock availability result. */
    private function generateMockResult(string $url): EndpointProbeResult
    {
        return EndpointProbeResult::fromArray([
            'reachable' => true,
            'httpCode' => 200,
            'latency_ms' => 50 + crc32($url) % 251,
            'error' => null,
            'url' => $url,
            'maintenance_mode' => false,
        ]);
    }
}
