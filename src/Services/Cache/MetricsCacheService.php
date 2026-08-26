<?php

declare(strict_types=1);

namespace Mata\Services\Cache;

use Monolog\Logger;

/**
 * Manages dashboard and server metrics caches.
 */
class MetricsCacheService
{
    /** @var array<string,mixed> */
    private array $serversConfig;

    /**
     * @param array<string,mixed> $serversConfig
     */
    public function __construct(
        private CacheStoreInterface $cacheStore,
        private ApiCacheKeyFactory $cacheKeyFactory,
        private Logger $logger,
        array $serversConfig
    ) {
        $this->serversConfig = $serversConfig;
    }

    /**
     * @param string[] $servers
     */
    public function buildDashboardCacheKey(
        array $servers,
        string $metric,
        string $timespan,
        string $range,
        string $granularity
    ): string {
        $normalizedServers = array_map('strtolower', $servers);
        sort($normalizedServers);

        return implode('|', [$metric, $timespan, $range, $granularity, implode(',', $normalizedServers)]);
    }

    /**
     * @return null|array<string,mixed>
     */
    public function readDashboardCache(string $cacheKey, int $ttl, bool $forceRefresh): ?array
    {
        $effectiveTtl = $forceRefresh ? 0 : $ttl;
        $entry = $this->cacheStore->get(
            $this->cacheKeyFactory->createDashboardMetricsKey($cacheKey),
            $effectiveTtl
        );
        if ($entry === null) {
            return null;
        }

        $age = max(0, time() - $entry->storedAt);
        $this->logger->debug('metrics.dashboard.cache.hit', ['key' => $cacheKey, 'age' => $age]);

        $payload = $this->normalizeStringKeyedArray($entry->value);
        $payload['cache'] = $this->buildCacheMetadata(true, $age, $effectiveTtl);

        return $payload;
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function writeDashboardCache(string $cacheKey, array $payload): void
    {
        $dataToCache = $payload;
        unset($dataToCache['cache']);

        if (!$this->cacheStore->put($this->cacheKeyFactory->createDashboardMetricsKey($cacheKey), $dataToCache)) {
            $this->logger->warning('metrics.dashboard.cache.write_failed', ['key' => $cacheKey]);

            return;
        }

        $this->logger->debug('metrics.dashboard.cache.store', ['key' => $cacheKey]);
    }

    /**
     * @return null|array{filters:array<string,string>,series:array<string,mixed>,cache:array<string,mixed>}
     */
    public function readSeriesCache(
        string $server,
        string $metric,
        string $range,
        string $granularity,
        int $ttl
    ): ?array {
        $key = $this->getSeriesCacheKey($server, $metric, $range, $granularity);
        if ($key === null) {
            return null;
        }

        $entry = $this->cacheStore->get($key, $ttl);
        if ($entry === null) {
            return null;
        }

        $rawSeries = $entry->value['series'] ?? null;
        $rawFilters = $entry->value['filters'] ?? null;
        if (!is_array($rawSeries) || !is_array($rawFilters)) {
            return null;
        }

        $series = $this->normalizeStringKeyedArray($rawSeries);
        $normalizedFilters = $this->normalizeStringKeyedArray($rawFilters);
        $filters = [];
        foreach ($normalizedFilters as $key => $value) {
            if (!is_string($value)) {
                return null;
            }
            $filters[$key] = $value;
        }

        $age = max(0, time() - $entry->storedAt);
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
     * @param array<string,mixed> $payload
     */
    public function writeSeriesCache(
        string $server,
        string $metric,
        string $range,
        string $granularity,
        array $payload
    ): void {
        $key = $this->getSeriesCacheKey($server, $metric, $range, $granularity);
        if ($key === null) {
            return;
        }

        $dataToCache = [
            'filters' => $payload['filters'],
            'series' => $payload['series'],
        ];

        if (!$this->cacheStore->put($key, $dataToCache)) {
            $this->logger->warning('metrics.cache.write_failed', [
                'server' => $server,
                'metric' => $metric,
                'range' => $range,
                'granularity' => $granularity,
            ]);

            return;
        }

        $this->logger->debug('metrics.cache.store', [
            'server' => $server,
            'metric' => $metric,
            'range' => $range,
            'granularity' => $granularity,
        ]);
    }

    /**
     * Removes entries with integer keys.
     *
     * @param array<int|string,mixed> $value
     *
     * @return array<string,mixed>
     */
    private function normalizeStringKeyedArray(array $value): array
    {
        $normalized = [];
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $normalized[$key] = $item;
            }
        }

        return $normalized;
    }

    private function getSeriesCacheKey(string $server, string $metric, string $range, string $granularity): ?string
    {
        $serverConfig = $this->serversConfig[$server] ?? null;
        $apiUrl = is_array($serverConfig) ? ($serverConfig['api_url'] ?? null) : null;
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

        return $this->cacheKeyFactory->createSeriesMetricsKey($apiUrl, $server, $metric, $range, $granularity);
    }

    /**
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
            'age' => $age,
        ];
    }
}
