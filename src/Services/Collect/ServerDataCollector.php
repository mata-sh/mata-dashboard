<?php

declare(strict_types=1);

namespace Mata\Services\Collect;

use GuzzleHttp\Exception\RequestException;
use Mata\Services\DataTransformers\ApiResponseTransformer;
use Mata\Services\Foundation\ApiClient;
use Mata\Services\Foundation\CacheManager;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Server data collection coordination service.
 *
 * Coordinates data collection from remote server nodes:
 * - Orchestrates API communication via ApiClient
 * - Manages response caching with TTL via CacheManager
 * - Transforms responses via DataTransformer
 * - Provides low-level data fetching primitives for monitors
 */
class ServerDataCollector implements ServerDataCollectorInterface
{
    private LoggerInterface $logger;
    private CacheManager $cacheManager;
    private ApiClient $apiClient;
    private ApiResponseTransformer $dataTransformer;

    /**
     * Initialize server data collector with coordinated services.
     *
     * @param LoggerInterface        $logger          PSR-3 logger for system logging and debugging
     * @param CacheManager           $cacheManager    Cache management service
     * @param ApiClient              $apiClient       HTTP communication client
     * @param ApiResponseTransformer $dataTransformer Response parsing and transformation service
     */
    public function __construct(
        LoggerInterface $logger,
        CacheManager $cacheManager,
        ApiClient $apiClient,
        ApiResponseTransformer $dataTransformer,
    ) {
        $this->logger = $logger;
        $this->cacheManager = $cacheManager;
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
    public function getServerSystemData(string $cacheType = 'serverData'): ?array
    {
        return $this->request('GET', 'server-data', $cacheType);
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
        return $this->request('GET', 'server-info', $cacheType);
    }

    /**
     * Fetch raw log data from a specific app.
     *
     * @param string $app       App identifier
     * @param string $cacheType Cache type key for TTL lookup (default: 'appLogs')
     * @param string $filter    Log filter type (default: 'errors')
     *
     * @return null|array<string> Raw log entries array or null on failure
     */
    public function getAppLogs(string $app, string $cacheType = 'appLogs', string $filter = 'errors'): ?array
    {
        return $this->request('GET', "{$app}/logs/{$filter}", $cacheType);
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
        return $this->request('GET', 'server-php', $cacheType);
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
        return $this->request('GET', 'server-databases', $cacheType);
    }

    /**
     * Fetch all apps/applications from the server.
     *
     * Retrieves comprehensive app information including:
     * - App titles and paths
     * - URLs and environments (dev, test, live)
     * - Maintenance status
     * - Error/warning counts and log file sizes
     * - Session statistics
     *
     * @param string $cacheType Cache type key for TTL lookup (default: 'apps')
     *
     * @return null|array<string,mixed> Apps data indexed by app name or null on failure
     */
    public function getApps(string $cacheType = 'apps'): ?array
    {
        return $this->request('GET', 'apps', $cacheType);
    }

    /**
     * Fetch status information for a specific app.
     *
     * @param string $app       App identifier
     * @param string $cacheType Cache type key for TTL lookup (default: 'apps')
     *
     * @return null|array<string,mixed> App status data or null on failure
     */
    public function getAppStatus(string $app, string $cacheType = 'apps'): ?array
    {
        return $this->request('GET', "app/{$app}/status", $cacheType);
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
     * @return array<string,mixed> Libraries data for the specified app
     */
    public function getAppLibraries(string $app, string $cacheType = 'apps'): array
    {
        return $this->request('GET', "app/{$app}/libraries", $cacheType);
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
     * Execute HTTP request with caching support.
     *
     * Handles API requests with intelligent caching:
     * - Checks cache validity based on TTL
     * - Falls back to stale cache on request failure
     * - Automatically creates cache directories
     * - Validates JSON responses
     *
     * @param string $method    HTTP method (GET, POST, etc.)
     * @param string $path      API endpoint path
     * @param string $cacheType Cache Type in seconds (0 = no cache, -1 = permanent cache)
     *
     * @return null|array<string,mixed> Response data or null on failure
     */
    private function request(string $method, string $path, string $cacheType = ''): ?array
    {
        $ttlSeconds = $this->cacheManager->getTtl($cacheType);
        $this->cacheManager->setCacheTtl($ttlSeconds);

        $cacheFile = $this->cacheManager->generateCachePath($this->apiClient->getApiBase(), $method, $path);

        // 1. Try fresh cache
        if ($cacheFile && $this->cacheManager->isFreshCache($cacheFile, $ttlSeconds)) {
            $cachedData = $this->cacheManager->readCache($cacheFile, $ttlSeconds);
            $cacheAge = time() - $this->cacheManager->getCacheAge();

            return $this->dataTransformer->normalizeApiResponse(
                data: $cachedData,
                cacheAge: $cacheAge,
                cacheTtl: $ttlSeconds,
                fromCache: true,
                path: $path,
            );
        }

        // 2. Try fresh API
        try {
            $rawData = $this->fetchFromApi($method, $path);
            if ($cacheFile) {
                $this->cacheManager->writeCache($cacheFile, $rawData);
            }

            return $this->dataTransformer->normalizeApiResponse(
                data: $rawData,
                cacheTtl: $ttlSeconds,
                path: $path,
            );
        } catch (RequestException $e) {
            $this->logger->warning("Fetching data failed trying stale cache: {$e->getMessage()}");

            // 3. Try stale cache
            if ($cacheFile && file_exists($cacheFile)) {
                $staleData = $this->cacheManager->readCache($cacheFile, 0);
                $cacheAge = time() - $this->cacheManager->getCacheAge();

                return $this->dataTransformer->normalizeApiResponse(
                    data: $staleData,
                    cacheAge: $cacheAge,
                    cacheTtl: $ttlSeconds,
                    fromCache: true,
                    staleCache: true,
                    path: $path,
                );
            }

            return null;
        }
    }

    /**
     * Fetch data from API and decode JSON response.
     *
     * @param string $method HTTP method
     * @param string $path   API endpoint path
     *
     * @return array<string,mixed> Decoded JSON response
     *
     * @throws RequestException When HTTP request fails
     * @throws RuntimeException When response transformation fails
     */
    private function fetchFromApi(string $method, string $path): array
    {
        $responseBody = $this->apiClient->request($method, $path);

        return $this->dataTransformer->transformResponse($responseBody, $path);
    }

    /**
     * Get the age of the last cached response.
     *
     * @return int Cache age timestamp or 0 if not set
     */
    public function getCacheAge(): int
    {
        return $this->cacheManager->getCacheAge();
    }

    /**
     * Get the TTL (Time To Live) of the current cache.
     *
     * @return int Cache TTL in seconds
     */
    public function getCacheTtl(): int
    {
        return $this->cacheManager->getCacheTtl();
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
