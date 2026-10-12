<?php

declare(strict_types=1);

namespace Mata\Services\Cache;

/**
 * Cache type identifiers used across the application.
 *
 * Backing values are the object keys of config/ttl.json and the allowed_keys list in
 * policy/ttl.rego, so they are a configuration contract: renaming a case value orphans
 * whatever TTL an operator has already configured under the old key.
 */
enum CacheType: string
{
    /**
     * Bypass the cache completely.
     *
     * A sentinel rather than a real bucket: it is not a valid ttl.json key, and
     * CacheTtlResolver refuses to look it up. See ttlConfigKeys().
     */
    case None = 'none';

    case ServerData = 'serverData';
    case ServerInfo = 'serverInfo';
    case ServerProcesses = 'serverProcesses';
    case ServerMetricsChart = 'serverMetricsChart';
    case Apps = 'apps';
    case Libraries = 'libraries';
    case AppLogs = 'appLogs';
    case Sessions = 'sessions';
    case Ping = 'ping';
    case Health = 'health';
    case Readme = 'readme';
    case Databases = 'databases';
    case Cronjobs = 'cronjobs';
    case Audit = 'audit';

    /**
     * Every backing value, including the None sentinel.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * The keys that may appear in config/ttl.json.
     *
     * This is the source of truth for policy/ttl.rego and
     * TtlConfig::default(); None is excluded because configuring a TTL
     * for it would be meaningless.
     *
     * @return list<string>
     */
    public static function ttlConfigKeys(): array
    {
        $keys = [];

        foreach (self::cases() as $type) {
            if ($type !== self::None) {
                $keys[] = $type->value;
            }
        }

        return $keys;
    }
}
