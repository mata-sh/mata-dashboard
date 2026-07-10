<?php

declare(strict_types=1);

namespace Mata\Services\Cache;

/**
 * Read-only cache TTL configuration lookup.
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
    public function get(string $cacheType): int
    {
        $ttl = $this->ttls[$cacheType] ?? null;

        return is_int($ttl) ? $ttl : 0;
    }
}
