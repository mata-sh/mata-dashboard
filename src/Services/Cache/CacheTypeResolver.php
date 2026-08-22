<?php

declare(strict_types=1);

namespace Mata\Services\Cache;

/**
 * Resolves cache type usage based on force refresh flags.
 *
 * Centralizes the logic that toggles between the default cache type and
 * bypassing the cache entirely. Keeps controllers and builders free from
 * repeated CacheType::None branching.
 */
final class CacheTypeResolver
{
    /**
     * Return the cache type to use for a request, falling back to CacheType::None
     * when a force refresh is requested.
     */
    public function resolve(bool $forceRefresh, CacheType $defaultCacheType): CacheType
    {
        return $forceRefresh ? CacheType::None : $defaultCacheType;
    }
}
