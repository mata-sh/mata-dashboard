<?php

declare(strict_types=1);

namespace Mata\Services\Cache;

use JsonException;

/**
 * Creates logical API cache keys without storage side effects.
 */
final class ApiCacheKeyFactory
{
    /** Synthetic host for dashboard-internal cache entries (not a real API). */
    private const DASHBOARD_HOST = 'https://mata-dashboard.local';

    /**
     * Create a key for an ordinary node API request.
     */
    public function createApiRequestKey(string $apiBase, string $method, string $path): string
    {
        try {
            return json_encode([
                'api_base' => $apiBase,
                'method' => $method,
                'path' => $path,
            ], JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $digest = hash('sha256', "{$apiBase}\0{$method}\0{$path}");

            return json_encode([
                'api_base' => 'invalid-key.local',
                'method' => 'GET',
                'path' => "invalid/{$digest}",
            ], JSON_THROW_ON_ERROR);
        }
    }

    /**
     * Create a key for a dashboard-internal resource under the synthetic host.
     */
    public function createDashboardKey(string $path): string
    {
        return $this->createApiRequestKey(self::DASHBOARD_HOST, 'GET', $path);
    }

    /**
     * Create a dashboard metrics key under the synthetic dashboard host.
     */
    public function createDashboardMetricsKey(string $logicalKey): string
    {
        return $this->createDashboardKey(
            'metrics/dashboard/' . $this->createOpaqueLeaf($logicalKey)
        );
    }

    /**
     * Create a node metrics-series key while retaining its API hierarchy.
     */
    public function createSeriesMetricsKey(
        string $apiBase,
        string $server,
        string $metric,
        string $range,
        string $granularity
    ): string {
        return $this->createApiRequestKey(
            $apiBase,
            'GET',
            sprintf('metrics/server/%s/%s/%s/%s', $server, $metric, $range, $granularity)
        );
    }

    /**
     * Create an availability key with an opaque URL-and-timeout leaf.
     */
    public function createAvailabilityKey(string $apiBase, CacheType $cacheType, string $url, int $timeout): string
    {
        return $this->createApiRequestKey(
            $apiBase,
            'HEAD',
            sprintf(
                'ping/%s/timeout_%d/%s',
                $cacheType->value,
                $timeout,
                $this->createOpaqueLeaf("{$url}|timeout_{$timeout}")
            )
        );
    }

    /**
     * Create a fixed-length, non-reversible cache path leaf.
     */
    public function createOpaqueLeaf(string $logicalKey): string
    {
        return hash('sha256', $logicalKey);
    }
}
