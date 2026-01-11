<?php

declare(strict_types=1);

namespace Mata\Services\Cache;

use Mata\Constants\CacheTypes;

/**
 * Resolves cache type usage based on force refresh flags.
 *
 * Centralizes the logic that toggles between the default cache type and
 * bypassing the cache entirely. Keeps controllers and builders free from
 * repeated CacheTypes::NONE branching.
 */
final class CacheTypeResolver
{
    /**
     * Return the cache type to use for a request, falling back to CacheTypes::NONE
     * when a force refresh is requested.
     */
    public function resolve(bool $forceRefresh, string $defaultCacheType): string
    {
        return $forceRefresh ? CacheTypes::NONE : $defaultCacheType;
    }
}
