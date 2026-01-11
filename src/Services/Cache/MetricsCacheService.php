<?php

declare(strict_types=1);

namespace Mata\Services\Cache;

use Mata\Services\Foundation\CacheManager;
use Monolog\Logger;

/**
 * Manages caching for metrics data.
 *
 * Provides two-tier caching strategy:
 * - Dashboard cache: Multi-server aggregated metrics for dashboard charts
 * - Series cache: Individual server time-series data with API URL-based paths
 *
 * Cache keys are normalized and organized hierarchically to prevent collisions
 * and support efficient invalidation.
 */
class MetricsCacheService
{
    private CacheManager $cacheManager;
    private Logger $logger;

    /** @var array<string,mixed> */
    private array $serversConfig;

    /**
     * @param array<string,mixed> $serversConfig
     */
    public function __construct(
        CacheManager $cacheManager,
        Logger $logger,
        array $serversConfig
    ) {
        $this->cacheManager = $cacheManager;
        $this->logger = $logger;
        $this->serversConfig = $serversConfig;
    }

    // ────────────────────────────────────────────────
    // Dashboard Cache Methods
    // ────────────────────────────────────────────────

    /**
     * Build cache key for dashboard metrics payload.
     *
     * @param string[] $servers
     */
    public function buildDashboardCacheKey(array $servers, string $metric, string $range, string $granularity): string
    {
        $normalizedServers = array_map('strtolower', $servers);
        sort($normalizedServers);

        return implode('|', [
            $metric,
            $range,
            $granularity,
            implode(',', $normalizedServers),
        ]);
    }

    /**
     * Read dashboard metrics from cache.
     *
     * @return null|array<string,mixed> Cached payload with metadata or null on miss
     */
    public function readDashboardCache(string $cacheKey, int $ttl, bool $forceRefresh): ?array
    {
        if ($forceRefresh || $ttl <= 0 || !$this->cacheManager->isCachingEnabled()) {
            return null;
        }

        $cachePath = $this->getDashboardCachePath($cacheKey);
        if ($cachePath === null || !$this->cacheManager->isFreshCache($cachePath, $ttl)) {
            return null;
        }

        $cached = $this->cacheManager->readCache($cachePath, $ttl);
        if (!is_array($cached)) {
            return null;
        }

        $age = $this->resolveCacheAge($cachePath);

        $this->logger->debug('metrics.dashboard.cache.hit', [
            'key' => $cacheKey,
            'age' => $age,
        ]);

        $cached['cache'] = $this->buildCacheMetadata(true, $age, $ttl);

        return $cached;
    }

    /**
     * Write dashboard metrics to cache.
     *
     * @param array<string,mixed> $payload
     */
    public function writeDashboardCache(string $cacheKey, array $payload, int $ttl): void
    {
        if ($ttl <= 0 || !$this->cacheManager->isCachingEnabled()) {
            return;
        }

        $cachePath = $this->getDashboardCachePath($cacheKey);
        if ($cachePath === null) {
            return;
        }

        $dataToCache = $payload;
        unset($dataToCache['cache']);

        $this->cacheManager->setCacheTtl($ttl);

        if (!$this->cacheManager->writeCache($cachePath, $dataToCache)) {
            $this->logger->warning('metrics.dashboard.cache.write_failed', [
                'key' => $cacheKey,
                'path' => $cachePath,
            ]);

            return;
        }

        $this->logger->debug('metrics.dashboard.cache.store', [
            'key' => $cacheKey,
            'ttl_seconds' => $ttl,
        ]);
    }

    /**
     * Get cache file path for dashboard metrics.
     */
    private function getDashboardCachePath(string $cacheKey): ?string
    {
        if (!$this->cacheManager->isCachingEnabled()) {
            return null;
        }

        $normalizedKey = $this->cacheManager->urlToKey($cacheKey);

        return $this->cacheManager->generateCachePath(
            'https://mata-dashboard.local',
            'GET',
            'metrics/dashboard/' . $normalizedKey
        );
    }

    // ────────────────────────────────────────────────
    // Series Cache Methods
    // ────────────────────────────────────────────────

    /**
     * Read server metric series from cache.
     *
     * @return null|array<string,mixed>
     */
    public function readSeriesCache(
        string $server,
        string $metric,
        string $range,
        string $granularity,
        int $ttl
    ): ?array {
        if ($ttl <= 0 || !$this->cacheManager->isCachingEnabled()) {
            return null;
        }

        $cachePath = $this->getSeriesCachePath($server, $metric, $range, $granularity);
        if ($cachePath === null || !$this->cacheManager->isFreshCache($cachePath, $ttl)) {
            return null;
        }

        $cached = $this->cacheManager->readCache($cachePath, $ttl);
        if (!is_array($cached)) {
            return null;
        }

        $series = is_array($cached['series'] ?? null) ? $cached['series'] : null;
        $filters = is_array($cached['filters'] ?? null) ? $cached['filters'] : null;

        if ($series === null || $filters === null) {
            return null;
        }

        $age = $this->resolveCacheAge($cachePath);

        $this->logger->debug('metrics.cache.hit', [
            'server' => $server,
            'metric' => $metric,
            'range' => $range,
            'granularity' => $granularity,
            'cache_age_seconds' => $age,
        ]);

        return [
            'filters' => $filters,
            'series' => $series,
            'cache' => $this->buildCacheMetadata(true, $age, $ttl),
        ];
    }

    /**
     * Write server metric series to cache.
     *
     * @param array<string,mixed> $payload
     */
    public function writeSeriesCache(
        string $server,
        string $metric,
        string $range,
        string $granularity,
        array $payload,
        int $ttl
    ): void {
        if ($ttl <= 0 || !$this->cacheManager->isCachingEnabled()) {
            return;
        }

        $cachePath = $this->getSeriesCachePath($server, $metric, $range, $granularity);
        if ($cachePath === null) {
            return;
        }

        $dataToCache = [
            'filters' => $payload['filters'],
            'series' => $payload['series'],
        ];

        $this->cacheManager->setCacheTtl($ttl);

        if (!$this->cacheManager->writeCache($cachePath, $dataToCache)) {
            $this->logger->warning('metrics.cache.write_failed', [
                'server' => $server,
                'metric' => $metric,
                'range' => $range,
                'granularity' => $granularity,
                'path' => $cachePath,
            ]);
        } else {
            $this->logger->debug('metrics.cache.store', [
                'server' => $server,
                'metric' => $metric,
                'range' => $range,
                'granularity' => $granularity,
                'ttl_seconds' => $ttl,
            ]);
        }
    }

    /**
     * Get cache file path for server metric series.
     */
    private function getSeriesCachePath(
        string $server,
        string $metric,
        string $range,
        string $granularity
    ): ?string {
        $serverConfig = $this->serversConfig[$server] ?? null;
        if (!is_array($serverConfig)) {
            $this->logger->warning('metrics.cache.skip', [
                'server' => $server,
                'metric' => $metric,
                'range' => $range,
                'granularity' => $granularity,
                'reason' => 'missing_server_config',
            ]);

            return null;
        }

        $apiUrl = $serverConfig['api_url'] ?? null;
        if (!is_string($apiUrl) || $apiUrl === '') {
            $this->logger->warning('metrics.cache.skip', [
                'server' => $server,
                'metric' => $metric,
                'range' => $range,
                'granularity' => $granularity,
                'reason' => 'missing_api_url',
            ]);

            return null;
        }

        $path = sprintf(
            'metrics/server/%s/%s/%s/%s',
            $server,
            $metric,
            $range,
            $granularity
        );

        return $this->cacheManager->generateCachePath(
            $apiUrl,
            'GET',
            $path
        );
    }

    // ────────────────────────────────────────────────
    // Cache Utilities
    // ────────────────────────────────────────────────

    /**
     * Resolve cache age in seconds from file modification time.
     */
    private function resolveCacheAge(string $cachePath): int
    {
        if (!file_exists($cachePath)) {
            return 0;
        }

        $age = time() - (int) filemtime($cachePath);

        return $age >= 0 ? $age : 0;
    }

    /**
     * Build cache metadata for response payload.
     *
     * @return array<string,mixed>
     */
    public function buildCacheMetadata(bool $fromCache, ?int $ageSeconds = null, ?int $ttlOverride = null): array
    {
        $age = $ageSeconds ?? 0;
        $ttl = $ttlOverride ?? 0;

        return [
            'from_cache' => $fromCache,
            'stale' => false,
            'ttl' => $ttl,
            'age' => $ttl > 0 ? $age : 0,
        ];
    }
}
