<?php

declare(strict_types=1);

namespace Mata\Services\Cache;

use Mata\Services\Collect\ServerDataCollectorInterface;

/**
 * Coordinates cache-related behaviour for modal endpoints.
 *
 * Wraps the force-refresh handling and CacheType switching, ensuring caching
 * decisions stay consistent across README, error log, and server info modals.
 */
final class ModalCacheService
{
    private CacheTypeResolver $cacheTypeResolver;

    public function __construct(CacheTypeResolver $cacheTypeResolver)
    {
        $this->cacheTypeResolver = $cacheTypeResolver;
    }

    /**
     * Fetch README data with cache handling applied.
     *
     * @return null|array<string,mixed>
     */
    public function fetchReadme(
        ServerDataCollectorInterface $collector,
        string $app,
        bool $forceRefresh
    ): ?array {
        $cacheType = $this->cacheTypeResolver->resolve($forceRefresh, CacheType::Readme);

        return $collector->fetchAppReadme($app, $cacheType);
    }

    /**
     * Fetch logs data with cache handling applied.
     *
     * @return null|array<string,mixed>
     */
    public function fetchLogs(
        ServerDataCollectorInterface $collector,
        string $app,
        bool $forceRefresh
    ): ?array {
        $cacheType = $this->cacheTypeResolver->resolve($forceRefresh, CacheType::AppLogs);

        return $collector->fetchAppLogs($app, $cacheType);
    }

    /**
     * Fetch logs status data with cache handling applied.
     *
     * @return null|array<string,mixed>
     */
    public function fetchLogsStatus(
        ServerDataCollectorInterface $collector,
        string $app,
        bool $forceRefresh
    ): ?array {
        $cacheType = $this->cacheTypeResolver->resolve($forceRefresh, CacheType::AppLogs);

        return $collector->fetchAppLogsStatus($app, $cacheType);
    }

    /**
     * Fetch database data with cache handling applied.
     *
     * @return null|array<string,mixed>|string
     */
    public function fetchServerDatabases(
        ServerDataCollectorInterface $collector,
        bool $forceRefresh
    ): array|string|null {
        $cacheType = $this->cacheTypeResolver->resolve($forceRefresh, CacheType::Databases);

        return $collector->getServerDatabases($cacheType);
    }

    /**
     * Fetch server info data with cache handling applied.
     *
     * @return null|array<string,mixed>
     */
    public function fetchServerInfo(
        ServerDataCollectorInterface $collector,
        bool $forceRefresh
    ): ?array {
        $cacheType = $this->cacheTypeResolver->resolve($forceRefresh, CacheType::ServerInfo);

        return $collector->getServerInfo($cacheType);
    }

    /**
     * Fetch server cronjobs data with cache handling applied.
     *
     * @return null|array<string,mixed>
     */
    public function fetchServerCronjobs(
        ServerDataCollectorInterface $collector,
        bool $forceRefresh
    ): ?array {
        $cacheType = $this->cacheTypeResolver->resolve($forceRefresh, CacheType::Cronjobs);

        return $collector->fetchServerCronjobs($cacheType);
    }
}
