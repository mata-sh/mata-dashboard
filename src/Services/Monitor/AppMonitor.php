<?php

declare(strict_types=1);

namespace Watcher\Services\Monitor;

use Psr\Log\LoggerInterface;
use Watcher\Constants\CacheTypes;
use Watcher\Services\Foundation\ApiClient;
use Watcher\Services\Foundation\CacheManager;
use Watcher\Services\Foundation\EventManager;
use Watcher\Services\Monitor\Dto\EndpointTarget;

/**
 * Application monitoring service responsible for availability checks.
 *
 * Handles caching, availability probing, and alarm/event signalling
 * for application endpoints.
 */
class AppMonitor implements AppMonitorInterface
{
    private const DEFAULT_AVAILABILITY_TIMEOUT = 5;

    public function __construct(
        private CacheManager $cacheManager,
        private ApiClient $apiClient,
        private EventManager $eventManager,
        private LoggerInterface $logger
    ) {}

    public function checkEndpoint(EndpointTarget $target, int $timeout = 2, string $cacheType = CacheTypes::PING): array
    {
        return $this->resolveAvailability($target->url, $cacheType, $timeout);
    }

    /**
     * @param array<string,EndpointTarget> $targets
     */
    public function checkMultipleEndpoints(array $targets, int $timeout = 2, string $cacheType = CacheTypes::PING, bool $async = false): array
    {
        if ($targets === []) {
            return [];
        }

        // TODO: wire in async handling when HTTP client supports it.
        $results = [];
        foreach ($targets as $identifier => $target) {
            $result = $this->resolveAvailability($target->url, $cacheType, $timeout);
            $results[$identifier] = $result;
            $this->recordUnreachableTarget($target, $result);
        }

        return $results;
    }

    /**
     * Resolve availability information with cache support.
     *
     * @return array<string,mixed>
     */
    private function resolveAvailability(string $url, string $cacheType, ?int $timeout = null): array
    {
        $ttlSeconds = $this->cacheManager->getTtl($cacheType);
        $this->cacheManager->setCacheTtl($ttlSeconds);

        $effectiveTimeout = $timeout ?? self::DEFAULT_AVAILABILITY_TIMEOUT;
        $cacheFile = null;

        if ($ttlSeconds !== 0 && $this->cacheManager->isCachingEnabled()) {
            $cacheKey = $this->cacheManager->urlToKey("{$url}|timeout_{$effectiveTimeout}");
            $cachePath = "ping/{$cacheType}/timeout_{$effectiveTimeout}/{$cacheKey}";
            $cacheFile = $this->cacheManager->generateCachePath(
                $this->apiClient->getApiBase(),
                'HEAD',
                $cachePath
            );

            if ($cacheFile && $this->cacheManager->isFreshCache($cacheFile, $ttlSeconds)) {
                $cached = $this->cacheManager->readCache($cacheFile, $ttlSeconds);
                if (is_array($cached)) {
                    return $cached;
                }
            }
        }

        $result = $this->apiClient->checkAvailability($url, $effectiveTimeout);

        if ($cacheFile) {
            $this->cacheManager->writeCache($cacheFile, $result);
        }

        return $result;
    }

    /**
     * Log unreachable endpoints as critical events.
     *
     * @param EndpointTarget      $target normalized target metadata
     * @param array<string,mixed> $result availability result data
     */
    private function recordUnreachableTarget(EndpointTarget $target, array $result): void
    {
        if ($result['reachable'] ?? false) {
            return;
        }

        $ctx = $this->extractEventContext($target);

        $message = "No response from {$ctx['identifier']} ({$ctx['url']})";

        $this->logger->warning("Ping -> {$message}", [
            'httpCode' => $result['httpCode'] ?? null,
            'error' => $result['error'] ?? null,
        ]);

        $this->eventManager->handleEvent(
            type: 'app_unreachable',
            message: $message,
            context: [
                'identifier' => $ctx['identifier'],
                'url' => $ctx['url'],
            ],
            level: 'critical',
            server: $ctx['server'],
            app: $ctx['app']
        );
    }

    private function extractEventContext(EndpointTarget $target): array
    {
        return [
            'identifier' => $target->identifier,
            'url' => $target->url,
            'server' => $target->serverId ?? $target->serverName ?? null,
            'app' => $target->applicationId,
        ];
    }
}
