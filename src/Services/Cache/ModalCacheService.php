<?php

declare(strict_types=1);

namespace Mata\Services\Cache;

use Mata\Constants\CacheTypes;
use Mata\Services\Collect\ServerDataCollectorInterface;

/**
 * Coordinates cache-related behaviour for modal endpoints.
 *
 * Wraps the force-refresh handling and CacheTypes switching, ensuring caching
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
     */
    public function fetchReadme(
        ServerDataCollectorInterface $collector,
        string $app,
        bool $forceRefresh
    ): ?array {
        $cacheType = $this->cacheTypeResolver->resolve($forceRefresh, CacheTypes::README);

        return $collector->fetchAppReadme($app, $cacheType);
    }

    /**
     * Fetch logs data with cache handling applied.
     */
    public function fetchLogs(
        ServerDataCollectorInterface $collector,
        string $app,
        bool $forceRefresh
    ): ?array {
        $cacheType = $this->cacheTypeResolver->resolve($forceRefresh, CacheTypes::APP_LOGS);

        return $collector->fetchAppLogs($app, $cacheType);
    }

    /**
     * Fetch logs status data with cache handling applied.
     */
    public function fetchLogsStatus(
        ServerDataCollectorInterface $collector,
        string $app,
        bool $forceRefresh
    ): ?array {
        $cacheType = $this->cacheTypeResolver->resolve($forceRefresh, CacheTypes::APP_LOGS);

        return $collector->fetchAppLogsStatus($app, $cacheType);
    }

    /**
     * Fetch server info data with cache handling applied.
     */
    public function fetchServerInfo(
        ServerDataCollectorInterface $collector,
        bool $forceRefresh
    ): ?array {
        $cacheType = $this->cacheTypeResolver->resolve($forceRefresh, CacheTypes::SERVER_INFO);

        return $collector->getServerInfo($cacheType);
    }

    /**
     * Fetch server cronjobs data with cache handling applied.
     */
    public function fetchServerCronjobs(
        ServerDataCollectorInterface $collector,
        bool $forceRefresh
    ): ?array {
        $cacheType = $this->cacheTypeResolver->resolve($forceRefresh, CacheTypes::CRONJOBS);

        return $collector->fetchServerCronjobs($cacheType);
    }
}
