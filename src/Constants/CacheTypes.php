<?php

declare(strict_types=1);

namespace Mata\Constants;

/**
 * Cache type constants used across the application.
 *
 * Defines standard cache type identifiers for the CacheManager service.
 * These constants ensure consistent cache type naming throughout controllers
 * and services.
 */
final class CacheTypes
{
    /**
     * No caching - bypass cache completely.
     */
    public const NONE = 'none';

    /**
     * Server metrics data (CPU, RAM, disk, processes).
     */
    public const SERVER_DATA = 'serverData';

    /**
     * Application list and metadata.
     */
    public const APPS = 'apps';

    /**
     * Application availability/ping results.
     */
    public const PING = 'ping';

    /**
     * Application error logs.
     */
    public const ERROR_LOG = 'errorlog';

    /**
     * Application README files.
     */
    public const README = 'readme';

    /**
     * Database information.
     */
    public const DATABASES = 'databases';

    /**
     * Server process information.
     */
    public const SERVER_PROCESSES = 'serverProcesses';

    /**
     * Application log analysis.
     */
    public const APP_LOGS = 'appLogs';

    /**
     * Server information (OS, PHP version, extensions).
     */
    public const SERVER_INFO = 'serverInfo';

    /**
     * Prevent instantiation - this is a constants-only class.
     */
    private function __construct() {}
}
