<?php

declare(strict_types=1);

namespace Mata\Services\Cache;

/**
 * Backend-neutral cache persistence contract.
 *
 * A TTL of zero bypasses cache reads: both reads miss. Negative TTLs are
 * permanent. getStale() ignores any non-zero TTL when reading stale entries.
 */
interface CacheStoreInterface
{
    /**
     * Return an entry only when it is fresh for the supplied TTL.
     */
    public function get(string $key, int $ttl): ?CacheEntry;

    /**
     * Return a readable entry regardless of age unless TTL is zero.
     */
    public function getStale(string $key, int $ttl): ?CacheEntry;

    /**
     * Persist an entry regardless of TTL read policy.
     *
     * @param array<int|string,mixed> $value
     */
    public function put(string $key, array $value): bool;
}
