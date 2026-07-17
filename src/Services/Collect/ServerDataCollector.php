<?php

declare(strict_types=1);

namespace Mata\Services\Collect;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\TransferException;
use Mata\Constants\CacheTypes;
use Mata\Services\Cache\ApiCacheKeyFactory;
use Mata\Services\Cache\CacheStoreInterface;
use Mata\Services\Cache\CacheTtlResolver;
use Mata\Services\DataTransformers\ApiResponseTransformer;
use Mata\Services\Foundation\ApiClient;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Server data collection coordination service.
 *
 * Coordinates data collection from remote server nodes:
 * - Orchestrates API communication via ApiClient
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
    private ApiClient $apiClient;
    private ApiResponseTransformer $dataTransformer;

    /**
     * Initialize server data collector with coordinated services.
     *
     * @param LoggerInterface        $logger          PSR-3 logger for system logging and debugging
     * @param CacheStoreInterface    $cacheStore      Cache persistence service
     * @param ApiCacheKeyFactory     $cacheKeyFactory API cache key factory
     * @param CacheTtlResolver       $ttlResolver     Cache TTL configuration lookup
     * @param ApiClient              $apiClient       HTTP communication client
     * @param ApiResponseTransformer $dataTransformer Response parsing and transformation service
     */
    public function __construct(
        LoggerInterface $logger,
        CacheStoreInterface $cacheStore,
        ApiCacheKeyFactory $cacheKeyFactory,
        CacheTtlResolver $ttlResolver,
        ApiClient $apiClient,
        ApiResponseTransformer $dataTransformer,
    ) {
        $this->logger = $logger;
        $this->cacheStore = $cacheStore;
        $this->cacheKeyFactory = $cacheKeyFactory;
        $this->ttlResolver = $ttlResolver;
        $this->apiClient = $apiClient;
        $this->dataTransformer = $dataTransformer;
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
     * @param string $cacheType Cache type key for TTL lookup (default: 'serverData')
     *
     * @return null|array<string,mixed> Server data or null on failure
     */
    public function getServerSystemData(string $cacheType = 'serverData', bool $forceRefresh = false): ?array
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
     * @param string $cacheType Cache type key for TTL lookup (default: 'serverInfo')
     *
     * @return null|array<string,mixed> Server info or null on failure
     */
    public function getServerInfo(string $cacheType = 'serverInfo'): ?array
    {
        return $this->request('GET', 'server/info', $cacheType);
    }

    /**
     * Fetch raw PHP process information from server.
     *
     * @param string $cacheType Cache type key for TTL lookup (default: 'serverProcesses')
     *
     * @return null|array<string,mixed> Raw PHP process data array or null on failure
     */
    public function getServerPhpProcesses(string $cacheType = 'serverProcesses'): ?array
    {
        return $this->request('GET', 'server/php', $cacheType);
    }

    /**
     * Fetch database server information and statistics.
     *
     * @param string $cacheType Cache type key for TTL lookup (default: 'databases')
     *
     * @return null|array<string,mixed>|string Database information or raw string data
     */
    public function getServerDatabases(string $cacheType = 'databases'): array|string|null
    {
        return $this->request('GET', 'server/databases', $cacheType);
    }

    /**
     * Fetch all apps/applications from the server.
     *
     * Retrieves comprehensive app information including:
     * - App titles and paths
     * - URLs and environments (dev, test, stg, live)
     * - Maintenance status
     * - Error/warning counts and log file sizes
     * - Session statistics
     *
     * @param string $cacheType Cache type key for TTL lookup (default: 'apps')
     *
     * @return null|array<string,mixed> Apps data indexed by app name or null on failure
     */
    public function getApps(string $cacheType = 'apps', bool $forceRefresh = false): ?array
    {
        return $this->request('GET', 'apps', $cacheType, forceRefresh: $forceRefresh);
    }

    /**
     * Fetch library information for all apps.
     *
     * Retrieves installed packages and their versions across all apps.
     *
     * @param string $cacheType Cache type key for TTL lookup (default: 'apps')
     *
     * @return null|array<string,mixed> Libraries data for all apps or null on failure
     */
    public function getAppsLibraries(string $cacheType = 'apps'): ?array
    {
        return $this->request('GET', 'apps/libraries', $cacheType);
    }

    /**
     * Fetch library information for a specific app.
     *
     * Retrieves installed packages and their versions for a single app.
     *
     * @param string $app       App identifier
     * @param string $cacheType Cache type key for TTL lookup (default: 'apps')
     *
     * @return null|array<string,mixed> Libraries data for the specified app or null on failure
     */
    public function getAppLibraries(string $app, string $cacheType = 'apps'): ?array
    {
        return $this->request('GET', "{$app}/libraries", $cacheType);
    }

    /**
     * Fetch README content from a specific app.
     *
     * @param string $app       App identifier
     * @param string $cacheType Cache type key for TTL lookup (default: 'readme')
     *
     * @return null|array<string,mixed> README content or null on failure
     */
    public function fetchAppReadme(string $app, string $cacheType = 'readme'): ?array
    {
        return $this->request('GET', "{$app}/readme", $cacheType);
    }

    /**
     * Fetch parsed log entries from multiple log files.
     * Returns grouped structure: ['file_path' => [entries...]].
     *
     * @param string $app       App identifier
     * @param string $cacheType Cache type key for TTL lookup (default: 'appLogs')
     *
     * @return null|array<string,mixed> Grouped log entries or null on failure
     */
    public function fetchAppLogs(string $app, string $cacheType = 'appLogs'): ?array
    {
        return $this->request('GET', "{$app}/logs", $cacheType);
    }

    /**
     * Fetch log file metadata (sizes, line counts, last modified).
     *
     * @param string $app       App identifier
     * @param string $cacheType Cache type key for TTL lookup (default: 'appLogs')
     *
     * @return null|array<string,mixed> Log file metadata or null on failure
     */
    public function fetchAppLogsStatus(string $app, string $cacheType = 'appLogs'): ?array
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
     * @param string $app       App identifier
     * @param string $cacheType Cache type key for TTL lookup (default: 'sessions')
     *
     * @return null|array<string,mixed> Session data or null on failure
     */
    public function fetchAppSessions(string $app, string $cacheType = 'sessions'): ?array
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
    public function fetchAppComposerAudit(string $app, string $cacheType = CacheTypes::AUDIT): ?array
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
        $ttlSeconds = $this->ttlResolver->get(CacheTypes::AUDIT);
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

        return $this->dataTransformer->normalizeApiResponse(
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
     * @param string $cacheType Cache type key for TTL lookup (default: 'cronjobs')
     *
     * @return null|array<string,mixed> Normalized response with cronjob entries or null on failure
     */
    public function fetchServerCronjobs(string $cacheType = 'cronjobs'): ?array
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
     * @param string              $cacheType      Cache Type in seconds (0 = no cache, -1 = permanent cache)
     * @param array<string,mixed> $requestOptions Guzzle request options
     *
     * @return null|array<string,mixed> Response data or null on failure
     */
    private function request(
        string $method,
        string $path,
        string $cacheType = '',
        array $requestOptions = [],
        bool $forceRefresh = false,
    ): ?array {
        $ttlSeconds = $this->ttlResolver->get($cacheType);
        $cacheKey = $this->cacheKeyFactory->createApiRequestKey($this->apiClient->getApiBase(), $method, $path);

        // 1. Try fresh cache
        $cachedEntry = $forceRefresh ? null : $this->cacheStore->get($cacheKey, $ttlSeconds);
        if ($cachedEntry !== null) {
            return $this->dataTransformer->normalizeApiResponse(
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

            return $this->dataTransformer->normalizeApiResponse(
                data: $rawData,
                cacheTtl: $ttlSeconds,
                path: $path,
            );
        } catch (TransferException $e) {
            // TransferException covers ConnectException (timeouts, DNS) which is NOT a RequestException
            $this->logger->warning("Fetching data failed trying stale cache: {$e->getMessage()}");

            // 3. Try stale cache
            $staleEntry = $this->cacheStore->getStale($cacheKey, $ttlSeconds);
            if ($staleEntry !== null) {
                return $this->dataTransformer->normalizeApiResponse(
                    data: $staleEntry->value,
                    cacheAge: max(0, time() - $staleEntry->storedAt),
                    cacheTtl: $ttlSeconds,
                    fromCache: true,
                    staleCache: true,
                    path: $path,
                );
            }

            // 4. Surface a structured node error when the failed response carried one
            $response = $e instanceof RequestException ? $e->getResponse() : null;
            if ($response !== null) {
                $nodeError = $this->dataTransformer->normalizeNodeErrorResponse(
                    (string) $response->getBody(),
                    $path,
                    $response->getStatusCode(),
                );
                if ($nodeError !== null) {
                    return $this->dataTransformer->errorApiResponse(
                        $nodeError,
                        cacheTtl: $ttlSeconds,
                    );
                }
            }

            return null;
        }
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

        return $this->dataTransformer->transformResponse($responseBody, $path);
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
        return $this->dataTransformer->isValidJsonArray($json);
    }

    // Availability checks were moved to AppMonitor.
}
