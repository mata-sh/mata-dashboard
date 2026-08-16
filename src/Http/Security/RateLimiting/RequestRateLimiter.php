<?php

declare(strict_types=1);

namespace Mata\Http\Security\RateLimiting;

use Mata\Config\Dto\RateLimitingConfig;
use MataSh\RateLimiter\RateLimiter;
use MataSh\RateLimiter\RateLimitIdentifier;
use MataSh\RateLimiter\RateLimitResult;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/** Applies package rate limits to dashboard requests. */
final class RequestRateLimiter
{
    public function __construct(
        private readonly ?RateLimiter $rateLimiter,
        private readonly RateLimitingConfig $config,
        private readonly LoggerInterface $logger,
    ) {}

    public function consume(string $group, ?string $ip = null): RateLimitResult
    {
        if (!$this->config->enabled) {
            return new RateLimitResult(true, 0, null);
        }

        $limits = $this->config->getLimitForGroup($group);

        try {
            return $this->requireRateLimiter()->consume(
                $this->identifier($group, $ip),
                $limits['maxRequests'],
                $limits['windowSeconds'],
            );
        } catch (Throwable $e) {
            $this->logger->error('Rate-limit request denied after limiter failure', [
                'group' => $group,
                'exception' => $e,
            ]);

            return new RateLimitResult(
                false,
                $limits['maxRequests'],
                time() + $limits['windowSeconds'],
            );
        }
    }

    private function requireRateLimiter(): RateLimiter
    {
        if ($this->rateLimiter === null) {
            throw new RuntimeException('Rate limiter is unavailable');
        }

        return $this->rateLimiter;
    }

    private function identifier(string $group, ?string $ip): string
    {
        return $group . '_' . RateLimitIdentifier::fromIp($ip);
    }
}
