<?php

declare(strict_types=1);

namespace Mata\Services\Collect;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\TransferException;
use Mata\Services\Cache\ApiCacheKeyFactory;
use Mata\Services\Cache\CacheStoreInterface;
use Mata\Services\Cache\CacheTtlResolver;
use Mata\Services\Cache\CacheType;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Server data collection coordination service.
 *
 * Coordinates data collection from remote server nodes:
 * - Orchestrates API communication via NodeApiClient
 * - Manages response caching with TTL via CacheStoreInterface
 * - Transforms responses via DataTransformer
 * - Provides low-level data fetching primitives for monitors
 */
class ServerDataCollector implements ServerDataCollectorInterface
{
    private LoggerInterface $logger;
    private CacheStoreInterface $cacheStore;
    private ApiCacheKeyFactory $cacheKeyFactory;
    private CacheTtlResolver $ttlResolver;
    private NodeApiClient $apiClient;
    private NodeResponseNormalizer $responseNormalizer;

    /**
     * Initialize server data collector with coordinated services.
     *
     * @param LoggerInterface        $logger             PSR-3 logger for system logging and debugging
     * @param CacheStoreInterface    $cacheStore         Cache persistence service
     * @param ApiCacheKeyFactory     $cacheKeyFactory    API cache key factory
     * @param CacheTtlResolver       $ttlResolver        Cache TTL configuration lookup
     * @param NodeApiClient          $apiClient          HTTP communication client
     * @param NodeResponseNormalizer $responseNormalizer Node response parsing and normalization
     */
    public function __construct(
        LoggerInterface $logger,
        CacheStoreInterface $cacheStore,
        ApiCacheKeyFactory $cacheKeyFactory,
        CacheTtlResolver $ttlResolver,
        NodeApiClient $apiClient,
        NodeResponseNormalizer $responseNormalizer,
    ) {
        $this->logger = $logger;
        $this->cacheStore = $cacheStore;
        $this->cacheKeyFactory = $cacheKeyFactory;
        $this->ttlResolver = $ttlResolver;
        $this->apiClient = $apiClient;
        $this->responseNormalizer = $responseNormalizer;
    }

    /**
     * Fetch comprehensive server data including system metrics.
     *
     * Retrieves server information such as:
     * - CPU usage percentage
     * - RAM capacity and usage
     * - Disk space capacity and usage
     * - Total system file count
     * - Process counts
     *
     * @param CacheType $cacheType Cache type key for TTL lookup (default: CacheType::ServerData)
     *
     * @return null|array<string,mixed> Server data or null on failure
     */
    public function getServerSystemData(CacheType $cacheType = CacheType::ServerData, bool $forceRefresh = false): ?array
    {
        return $this->request('GET', 'server/data', $cacheType, forceRefresh: $forceRefresh);
    }

    /**
     * Fetch server information including OS version, packages, and PHP extensions.
     *
     * Retrieves server environment information such as:
     * - Operating system name and version
     * - Total installed packages count
     * - List of activated PHP extensions
     *
     * @param CacheType $cacheType Cache type key for TTL lookup (default: CacheType::ServerInfo)
     *
     * @return null|array<string,mixed> Server info or null on failure
     */
    public function getServerInfo(CacheType $cacheType = CacheType::ServerInfo): ?array
    {
        return $this->request('GET', 'server/info', $cacheType);
    }

    /**
     * Fetch instance-wide database status and the monitored database inventory.
     *
     * A node error (monitoring not configured, database down) wins over stale data,
     * so an outage or a disabled section never hides behind old numbers.
     *
     * @return null|array<string,mixed> Normalized overview response or null on failure
     */
    public function getDatabaseOverview(CacheType $cacheType = CacheType::Databases): ?array
    {
        return $this->request('GET', 'databases/overview', $cacheType, nodeErrorOverridesStale: true);
    }

    /**
     * Fetch the live database process list; never cached because it is diagnostic.
     *
     * @param null|string $database Monitored database name, or null for all monitored databases
     *
     * @return null|array<string,mixed> Normalized process-list response or null on failure
     */
    public function fetchDatabaseProcesses(?string $database): ?array
    {
        $path = 'databases/processes';
        $query = ['active_only' => 0, 'limit' => 100];
        if ($database !== null) {
            $query['database'] = $database;
        }

        try {
            return $this->responseNormalizer->normalizeApiResponse(
                data: $this->fetchFromApi('GET', $path, ['query' => $query]),
                path: $path,
            );
        } catch (TransferException $e) {
            $this->logger->warning("Fetching database processes failed: {$e->getMessage()}");

            return $this->nodeErrorResponse($e, $path, 0);
        }
    }

    /**
     * Fetch all apps/applications from the server.
     *
     * Retrieves comprehensive app information including:
     * - App titles and paths
     * - URLs and environments (dev, test, stg, prod)
     * - Maintenance status
     * - Error/warning counts and log file sizes
     * - Session statistics
     *
     * @param CacheType $cacheType Cache type key for TTL lookup (default: CacheType::Apps)
     *
     * @return null|array<string,mixed> Apps data indexed by app name or null on failure
     */
    public function getApps(CacheType $cacheType = CacheType::Apps, bool $forceRefresh = false): ?array
    {
        return $this->request('GET', 'apps', $cacheType, forceRefresh: $forceRefresh);
    }

    /**
     * Fetch library information for all apps.
     *
     * Retrieves installed packages and their versions across all apps.
     *
     * @param CacheType $cacheType Cache type key for TTL lookup (default: CacheType::Libraries)
     *
     * @return null|array<string,mixed> Libraries data for all apps or null on failure
     */
    public function getAppsLibraries(CacheType $cacheType = CacheType::Libraries): ?array
    {
        return $this->request('GET', 'apps/libraries', $cacheType);
    }

    /**
     * Fetch README content from a specific app.
     *
     * @param string    $app       App identifier
     * @param CacheType $cacheType Cache type key for TTL lookup (default: CacheType::Readme)
     *
     * @return null|array<string,mixed> README content or null on failure
     */
    public function fetchAppReadme(string $app, CacheType $cacheType = CacheType::Readme): ?array
    {
        return $this->request('GET', "{$app}/readme", $cacheType);
    }

    /**
     * Fetch parsed log entries from multiple log files.
     * Returns grouped structure: ['file_path' => [entries...]].
     *
     * @param string    $app       App identifier
     * @param CacheType $cacheType Cache type key for TTL lookup (default: CacheType::AppLogs)
     *
     * @return null|array<string,mixed> Grouped log entries or null on failure
     */
    public function fetchAppLogs(string $app, CacheType $cacheType = CacheType::AppLogs): ?array
    {
        return $this->request('GET', "{$app}/logs", $cacheType);
    }

    /**
     * Fetch log file metadata (sizes, line counts, last modified).
     *
     * @param string    $app       App identifier
     * @param CacheType $cacheType Cache type key for TTL lookup (default: CacheType::AppLogs)
     *
     * @return null|array<string,mixed> Log file metadata or null on failure
     */
    public function fetchAppLogsStatus(string $app, CacheType $cacheType = CacheType::AppLogs): ?array
    {
        return $this->request('GET', "{$app}/logs-status", $cacheType);
    }

    /**
     * Fetch session status for a specific app.
     *
     * Returns the nested session object: `type`, `status`, `count_last_hour`,
     * `count_last_day`, `count_total`, `path`, `size_kb`, and `error` when
     * status is error/unsupported.
     *
     * @param string    $app       App identifier
     * @param CacheType $cacheType Cache type key for TTL lookup (default: CacheType::Sessions)
     *
     * @return null|array<string,mixed> Session data or null on failure
     */
    public function fetchAppSessions(string $app, CacheType $cacheType = CacheType::Sessions): ?array
    {
        return $this->request('GET', "{$app}/sessions", $cacheType);
    }

    /**
     * Fetch Composer audit results for a specific app.
     *
     * The HTTP timeout exceeds the node's internal 60-second Composer timeout.
     *
     * @return null|array<string,mixed> Normalized Composer audit response or null on failure
     */
    public function fetchAppComposerAudit(string $app, CacheType $cacheType = CacheType::Audit): ?array
    {
        return $this->request('GET', "{$app}/composer-audit", $cacheType, ['timeout' => 90]);
    }

    /**
     * Return cached Composer audit results for a specific app without requesting the node.
     *
     * @return null|array<string,mixed> Normalized cached Composer audit response or null when unavailable
     */
    public function getCachedAppComposerAudit(string $app): ?array
    {
        $ttlSeconds = $this->ttlResolver->get(CacheType::Audit);
        $path = "{$app}/composer-audit";
        $cacheKey = $this->cacheKeyFactory->createApiRequestKey($this->apiClient->getApiBase(), 'GET', $path);

        $entry = $this->cacheStore->get($cacheKey, $ttlSeconds);
        $staleCache = false;
        if ($entry === null) {
            $entry = $this->cacheStore->getStale($cacheKey, $ttlSeconds);
            $staleCache = $entry !== null;
        }

        if ($entry === null) {
            return null;
        }

        return $this->responseNormalizer->normalizeApiResponse(
            data: $entry->value,
            cacheAge: max(0, time() - $entry->storedAt),
            cacheTtl: $ttlSeconds,
            fromCache: true,
            staleCache: $staleCache,
            path: $path,
        );
    }

    /**
     * Fetch server cronjob heartbeat entries.
     *
     * Ordered list of entries with `index`, `path`, `exists`, `mtime`,
     * `age_seconds`, `size`, `stale`, `content_tail`.
     *
     * @param CacheType $cacheType Cache type key for TTL lookup (default: CacheType::Cronjobs)
     *
     * @return null|array<string,mixed> Normalized response with cronjob entries or null on failure
     */
    public function fetchServerCronjobs(CacheType $cacheType = CacheType::Cronjobs): ?array
    {
        return $this->request('GET', 'server/cronjobs', $cacheType);
    }

    /**
     * Execute HTTP request with caching support.
     *
     * Handles API requests with intelligent caching:
     * - Checks cache validity based on TTL
     * - Falls back to stale cache on request failure
     * - Automatically creates cache directories
     * - Validates JSON responses
     *
     * @param string              $method         HTTP method (GET, POST, etc.)
     * @param string              $path           API endpoint path
     * @param CacheType           $cacheType      selects the TTL; CacheType::None bypasses the cache
     * @param array<string,mixed> $requestOptions Guzzle request options
     *
     * @return null|array<string,mixed> Response data or null on failure
     */
    private function request(
        string $method,
        string $path,
        CacheType $cacheType,
        array $requestOptions = [],
        bool $forceRefresh = false,
        bool $nodeErrorOverridesStale = false,
    ): ?array {
        $ttlSeconds = $this->ttlResolver->get($cacheType);
        $cacheKey = $this->cacheKeyFactory->createApiRequestKey($this->apiClient->getApiBase(), $method, $path);

        // 1. Try fresh cache
        $cachedEntry = $forceRefresh ? null : $this->cacheStore->get($cacheKey, $ttlSeconds);
        if ($cachedEntry !== null) {
            return $this->responseNormalizer->normalizeApiResponse(
                data: $cachedEntry->value,
                cacheAge: max(0, time() - $cachedEntry->storedAt),
                cacheTtl: $ttlSeconds,
                fromCache: true,
                path: $path,
            );
        }

        // 2. Try fresh API
        try {
            $rawData = $this->fetchFromApi($method, $path, $requestOptions);
            $this->cacheStore->put($cacheKey, $rawData);

            return $this->responseNormalizer->normalizeApiResponse(
                data: $rawData,
                cacheTtl: $ttlSeconds,
                path: $path,
            );
        } catch (TransferException $e) {
            // TransferException covers ConnectException (timeouts, DNS) which is NOT a RequestException
            $this->logger->warning("Fetching data failed trying stale cache: {$e->getMessage()}");

            $nodeError = $nodeErrorOverridesStale ? $this->nodeErrorResponse($e, $path, $ttlSeconds) : null;
            if ($nodeError !== null) {
                return $nodeError;
            }

            // 3. Try stale cache
            $staleEntry = $this->cacheStore->getStale($cacheKey, $ttlSeconds);
            if ($staleEntry !== null) {
                return $this->responseNormalizer->normalizeApiResponse(
                    data: $staleEntry->value,
                    cacheAge: max(0, time() - $staleEntry->storedAt),
                    cacheTtl: $ttlSeconds,
                    fromCache: true,
                    staleCache: true,
                    path: $path,
                );
            }

            // 4. Surface a structured node error when the failed response carried one
            return $this->nodeErrorResponse($e, $path, $ttlSeconds);
        }
    }

    /**
     * Build an error envelope from a structured node error body, if the failure carried one.
     *
     * @return null|array<string,mixed>
     */
    private function nodeErrorResponse(TransferException $e, string $path, int $ttlSeconds): ?array
    {
        $response = $e instanceof RequestException ? $e->getResponse() : null;
        if ($response === null) {
            return null;
        }

        $nodeError = $this->responseNormalizer->normalizeNodeErrorResponse(
            (string) $response->getBody(),
            $path,
            $response->getStatusCode(),
        );

        return $nodeError === null ? null : $this->responseNormalizer->errorApiResponse($nodeError, cacheTtl: $ttlSeconds);
    }

    /**
     * Fetch data from API and decode JSON response.
     *
     * @param string              $method         HTTP method
     * @param string              $path           API endpoint path
     * @param array<string,mixed> $requestOptions Guzzle request options
     *
     * @return array<string,mixed> Decoded JSON response
     *
     * @throws TransferException When HTTP request fails
     * @throws RuntimeException  When response transformation fails
     */
    private function fetchFromApi(string $method, string $path, array $requestOptions = []): array
    {
        $responseBody = $this->apiClient->request($method, $path, $requestOptions);

        return $this->responseNormalizer->transformResponse($responseBody, $path);
    }

    /**
     * Validate if a string contains valid JSON array data.
     *
     * @param string $json JSON string to validate
     *
     * @return bool True if valid JSON array, false otherwise
     */
    public function isValidJsonArray(string $json): bool
    {
        return $this->responseNormalizer->isValidJsonArray($json);
    }

    // Availability checks were moved to AppReachabilityMonitor.
}
