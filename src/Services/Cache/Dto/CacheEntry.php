<?php

declare(strict_types=1);

namespace Mata\Services\Cache\Dto;

/**
 * Immutable cache value with its storage timestamp.
 */
final readonly class CacheEntry
{
    /**
     * @param array<int|string,mixed> $value
     */
    public function __construct(
        public array $value,
        public int $storedAt
    ) {}
}
