<?php

declare(strict_types=1);

namespace Mata\Services\Monitor;

use Mata\Constants\CacheType;
use Mata\Services\Monitor\Dto\EndpointProbeResult;
use Mata\Services\Monitor\Dto\EndpointTarget;

/**
 * Demo implementation of the AppReachabilityMonitor that simulates endpoint reachability.
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
        $isMaintenance = mt_rand(1, 100) <= 3;
        $isReachable = !$isMaintenance && mt_rand(1, 100) > 5;

        return EndpointProbeResult::fromArray([
            'reachable' => $isReachable,
            'httpCode' => $isMaintenance ? 503 : ($isReachable ? 200 : 0),
            'latency_ms' => $isReachable ? mt_rand(50, 300) : 0,
            'error' => $isReachable || $isMaintenance ? null : "Mock timeout when accessing {$url}",
            'url' => $url,
            'maintenance_mode' => $isMaintenance,
        ]);
    }
}
