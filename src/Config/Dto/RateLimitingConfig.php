<?php

declare(strict_types=1);

namespace Mata\Config\Dto;

use InvalidArgumentException;

/**
 * Immutable DTO representing rate limiting configuration.
 *
 * Contains settings for rate limiting including storage backend,
 * connection parameters, and per-route-group limits.
 */
final class RateLimitingConfig
{
    public function __construct(
        public readonly bool $enabled,
        public readonly string $storageType,
        public readonly ?string $filePath,
        public readonly ?string $redisHost,
        public readonly int $redisPort,
        public readonly ?string $redisPassword,
        public readonly int $redisDatabase,
        public readonly int $loginMaxRequests,
        public readonly int $loginWindowSeconds,
        public readonly int $dashboardMaxRequests,
        public readonly int $dashboardWindowSeconds,
        public readonly int $apiMaxRequests,
        public readonly int $apiWindowSeconds,
    ) {
        if ($redisPort < 1 || $redisPort > 65535) {
            throw new InvalidArgumentException('rate_limiting.redis_port must be between 1 and 65535.');
        }
        if ($redisDatabase < 0) {
            throw new InvalidArgumentException('rate_limiting.redis_database must be at least 0.');
        }
        foreach ([
            'login_max_requests' => $loginMaxRequests,
            'login_window_seconds' => $loginWindowSeconds,
            'dashboard_max_requests' => $dashboardMaxRequests,
            'dashboard_window_seconds' => $dashboardWindowSeconds,
            'api_max_requests' => $apiMaxRequests,
            'api_window_seconds' => $apiWindowSeconds,
        ] as $path => $value) {
            if ($value < 1) {
                throw new InvalidArgumentException("rate_limiting.{$path} must be at least 1.");
            }
        }
    }

    /**
     * Get rate limit configuration for specific route group.
     *
     * @param string $group Route group: 'login', 'dashboard', 'api'
     *
     * @return array{maxRequests: int, windowSeconds: int}
     */
    public function getLimitForGroup(string $group): array
    {
        return match ($group) {
            'login' => [
                'maxRequests' => $this->loginMaxRequests,
                'windowSeconds' => $this->loginWindowSeconds,
            ],
            'dashboard' => [
                'maxRequests' => $this->dashboardMaxRequests,
                'windowSeconds' => $this->dashboardWindowSeconds,
            ],
            'api' => [
                'maxRequests' => $this->apiMaxRequests,
                'windowSeconds' => $this->apiWindowSeconds,
            ],
            default => [
                'maxRequests' => 60,
                'windowSeconds' => 60,
            ],
        };
    }
}
