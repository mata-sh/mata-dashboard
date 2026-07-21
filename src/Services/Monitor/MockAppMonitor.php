<?php

declare(strict_types=1);

namespace Mata\Services\Monitor;

use Mata\Constants\CacheTypes;
use Mata\Services\Monitor\Dto\EndpointTarget;

/**
 * Demo implementation of the AppMonitor that simulates availability.
 */
class MockAppMonitor implements AppMonitorInterface
{
    public function checkEndpoint(
        EndpointTarget $target,
        ?int $timeout = null,
        string $cacheType = CacheTypes::PING,
        bool $forceRefresh = false,
    ): array {
        return $this->generateMockResult($target->url);
    }

    /**
     * @param array<string,EndpointTarget> $targets
     */
    public function checkMultipleEndpoints(
        array $targets,
        ?int $timeout = null,
        string $cacheType = CacheTypes::PING,
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

    /**
     * Produce a mock availability result.
     *
     * @return array<string,mixed>
     */
    private function generateMockResult(string $url): array
    {
        $isMaintenance = mt_rand(1, 100) <= 3;
        $isReachable = !$isMaintenance && mt_rand(1, 100) > 5;

        return [
            'reachable' => $isReachable,
            'httpCode' => $isMaintenance ? 503 : ($isReachable ? 200 : 0),
            'latency_ms' => $isReachable ? mt_rand(50, 300) : 0,
            'error' => $isReachable || $isMaintenance ? null : "Mock timeout when accessing {$url}",
            'maintenance_mode' => $isMaintenance,
        ];
    }
}
