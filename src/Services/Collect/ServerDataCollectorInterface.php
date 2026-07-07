<?php

declare(strict_types=1);

namespace Mata\Services\Collect;

/**
 * Server data collection interface.
 *
 * Defines the contract for server data collection services,
 * allowing both real API-based and mock implementations
 */
interface ServerDataCollectorInterface
{
    /**
     * Fetch comprehensive server data including system metrics.
     *
     * @param string $cacheType Cache type key for TTL lookup (default: 'serverData')
     *
     * @return null|array<string,mixed> Server data or null on failure
     */
    public function getServerSystemData(string $cacheType = 'serverData'): ?array;

    /**
     * Fetch raw PHP process information from server.
     *
     * @param string $cacheType Cache type key for TTL lookup (default: 'serverProcesses')
     *
     * @return null|array<string,mixed> Raw PHP process data array or null on failure
     */
    public function getServerPhpProcesses(string $cacheType = 'serverProcesses'): ?array;

    /**
     * Fetch database server information and statistics.
     *
     * @param string $cacheType Cache type key for TTL lookup (default: 'databases')
     *
     * @return null|array<string,mixed>|string Database information or raw string data
     */
    public function getServerDatabases(string $cacheType = 'databases'): array|string|null;

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
    public function getServerInfo(string $cacheType = 'serverInfo'): ?array;

    /**
     * Fetch all apps/applications from the server.
     *
     * @param string $cacheType Cache type key for TTL lookup (default: 'apps')
     *
     * @return null|array<string,mixed> Apps data indexed by app name or null on failure
     */
    public function getApps(string $cacheType = 'apps'): ?array;

    /**
     * Fetch library information for all apps.
     *
     * @param string $cacheType Cache type key for TTL lookup (default: 'apps')
     *
     * @return null|array<string,mixed> Libraries data for all apps or null on failure
     */
    public function getAppsLibraries(string $cacheType = 'apps'): ?array;

    /**
     * Fetch library information for a specific app.
     *
     * @param string $app       App identifier
     * @param string $cacheType Cache type key for TTL lookup (default: 'apps')
     *
     * @return null|array<string,mixed> Libraries data for the specified app or null on failure
     */
    public function getAppLibraries(string $app, string $cacheType = 'apps'): ?array;

    /**
     * Fetch README content from a specific app.
     *
     * @param string $app       App identifier
     * @param string $cacheType Cache type key for TTL lookup (default: 'readme')
     *
     * @return null|array<string,mixed> README content or null on failure
     */
    public function fetchAppReadme(string $app, string $cacheType = 'readme'): ?array;

    /**
     * Fetch parsed log entries from multiple log files.
     * Returns grouped structure: ['file_path' => [entries...]].
     *
     * @param string $app       App identifier
     * @param string $cacheType Cache type key for TTL lookup (default: 'appLogs')
     *
     * @return null|array<string,mixed> Grouped log entries or null on failure
     */
    public function fetchAppLogs(string $app, string $cacheType = 'appLogs'): ?array;

    /**
     * Fetch log file metadata (sizes, line counts, last modified).
     *
     * @param string $app       App identifier
     * @param string $cacheType Cache type key for TTL lookup (default: 'appLogs')
     *
     * @return null|array<string,mixed> Log file metadata or null on failure
     */
    public function fetchAppLogsStatus(string $app, string $cacheType = 'appLogs'): ?array;

    /**
     * Get the age of the last cached response.
     *
     * @return int Cache age timestamp or 0 if not set
     */
    public function getCacheAge(): int;

    /**
     * Get the TTL (Time To Live) of the current cache.
     *
     * @return int Cache TTL in seconds
     */
    public function getCacheTtl(): int;

    /**
     * Validate if a string contains valid JSON array data.
     *
     * @param string $json JSON string to validate
     *
     * @return bool True if valid JSON array, false otherwise
     */
    public function isValidJsonArray(string $json): bool;
}
