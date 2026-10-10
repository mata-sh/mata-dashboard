<?php

declare(strict_types=1);

namespace Mata\Services\Collect;

use Mata\Services\Cache\CacheType;

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
     * @param CacheType $cacheType    cache key for TTL lookup (default: CacheType::ServerData)
     * @param bool      $forceRefresh bypass a cached result while preserving its cache key
     *
     * @return null|array<string,mixed> Server data or null on failure
     */
    public function getServerSystemData(CacheType $cacheType = CacheType::ServerData, bool $forceRefresh = false): ?array;

    /**
     * Fetch raw PHP process information from server.
     *
     * @param CacheType $cacheType Cache type key for TTL lookup (default: CacheType::ServerProcesses)
     *
     * @return null|array<string,mixed> Raw PHP process data array or null on failure
     */
    public function getServerPhpProcesses(CacheType $cacheType = CacheType::ServerProcesses): ?array;

    /**
     * Fetch instance-wide database status and the monitored database inventory.
     *
     * @return null|array<string,mixed> Normalized overview response or null on failure
     */
    public function getDatabaseOverview(CacheType $cacheType = CacheType::Databases): ?array;

    /**
     * Fetch the live database process list, optionally limited to one monitored database.
     *
     * @return null|array<string,mixed> Normalized process-list response or null on failure
     */
    public function fetchDatabaseProcesses(?string $database): ?array;

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
    public function getServerInfo(CacheType $cacheType = CacheType::ServerInfo): ?array;

    /**
     * Fetch all apps/applications from the server.
     *
     * @param CacheType $cacheType    cache key for TTL lookup (default: CacheType::Apps)
     * @param bool      $forceRefresh bypass a cached result while preserving its cache key
     *
     * @return null|array<string,mixed> Apps data indexed by app name or null on failure
     */
    public function getApps(CacheType $cacheType = CacheType::Apps, bool $forceRefresh = false): ?array;

    /**
     * Fetch library information for all apps.
     *
     * @param CacheType $cacheType Cache type key for TTL lookup (default: CacheType::Libraries)
     *
     * @return null|array<string,mixed> Libraries data for all apps or null on failure
     */
    public function getAppsLibraries(CacheType $cacheType = CacheType::Libraries): ?array;

    /**
     * Fetch README content from a specific app.
     *
     * @param string    $app       App identifier
     * @param CacheType $cacheType Cache type key for TTL lookup (default: CacheType::Readme)
     *
     * @return null|array<string,mixed> README content or null on failure
     */
    public function fetchAppReadme(string $app, CacheType $cacheType = CacheType::Readme): ?array;

    /**
     * Fetch parsed log entries from multiple log files.
     * Returns grouped structure: ['file_path' => [entries...]].
     *
     * @param string    $app       App identifier
     * @param CacheType $cacheType Cache type key for TTL lookup (default: CacheType::AppLogs)
     *
     * @return null|array<string,mixed> Grouped log entries or null on failure
     */
    public function fetchAppLogs(string $app, CacheType $cacheType = CacheType::AppLogs): ?array;

    /**
     * Fetch log file metadata (sizes, line counts, last modified).
     *
     * @param string    $app       App identifier
     * @param CacheType $cacheType Cache type key for TTL lookup (default: CacheType::AppLogs)
     *
     * @return null|array<string,mixed> Log file metadata or null on failure
     */
    public function fetchAppLogsStatus(string $app, CacheType $cacheType = CacheType::AppLogs): ?array;

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
    public function fetchAppSessions(string $app, CacheType $cacheType = CacheType::Sessions): ?array;

    /**
     * Fetch Composer audit results for a specific app.
     *
     * @return null|array<string,mixed> Normalized Composer audit response or null on failure
     */
    public function fetchAppComposerAudit(string $app, CacheType $cacheType = CacheType::Audit): ?array;

    /**
     * Return cached Composer audit results for a specific app without requesting the node.
     *
     * @return null|array<string,mixed> Normalized cached Composer audit response or null when unavailable
     */
    public function getCachedAppComposerAudit(string $app): ?array;

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
    public function fetchServerCronjobs(CacheType $cacheType = CacheType::Cronjobs): ?array;
}
