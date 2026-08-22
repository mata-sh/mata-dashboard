<?php

declare(strict_types=1);

namespace Mata\Services\Cache;

use Mata\Constants\CacheType;

/**
 * Read-only cache TTL configuration lookup.
 *
 * The single place that maps a CacheType to a ttl.json key, so the configuration
 * contract lives here rather than at every call site.
 */
final class CacheTtlResolver
{
    /**
     * @param array<string,mixed> $ttls
     */
    public function __construct(private array $ttls) {}

    /**
     * Return zero for missing or invalid configuration values.
     */
    public function get(CacheType $cacheType): int
    {
        // None means "bypass the cache", so it is never looked up. Guarding here rather
        // than relying on 'none' being absent from ttl.json makes it impossible for a
        // stray "none" config key to start caching uncacheable requests.
        if ($cacheType === CacheType::None) {
            return 0;
        }

        $ttl = $this->ttls[$cacheType->value] ?? null;

        return is_int($ttl) ? $ttl : 0;
    }
}
