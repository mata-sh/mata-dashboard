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
    public function checkEndpoint(EndpointTarget $target, int $timeout = 2, string $cacheType = CacheTypes::PING): array
    {
        return $this->generateMockResult($target->url);
    }

    /**
     * @param array<string,EndpointTarget> $targets
     */
    public function checkMultipleEndpoints(array $targets, int $timeout = 2, string $cacheType = CacheTypes::PING, bool $async = false): array
    {
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
        $isReachable = mt_rand(1, 100) > 5;

        return [
            'reachable' => $isReachable,
            'httpCode' => $isReachable ? 200 : 0,
            'latency_ms' => $isReachable ? mt_rand(50, 300) : 0,
            'error' => $isReachable ? null : "Mock timeout when accessing {$url}",
        ];
    }
}
