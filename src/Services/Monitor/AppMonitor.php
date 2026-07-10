<?php

declare(strict_types=1);

namespace Mata\Services\Monitor;

use Mata\Constants\CacheTypes;
use Mata\Services\Cache\ApiCacheKeyFactory;
use Mata\Services\Cache\CacheStoreInterface;
use Mata\Services\Cache\CacheTtlResolver;
use Mata\Services\Event\EventManager;
use Mata\Services\Foundation\ApiClient;
use Mata\Services\Monitor\Dto\EndpointTarget;
use Psr\Log\LoggerInterface;

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
        private CacheStoreInterface $cacheStore,
        private ApiCacheKeyFactory $cacheKeyFactory,
        private CacheTtlResolver $ttlResolver,
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
        $ttlSeconds = $this->ttlResolver->get($cacheType);
        $effectiveTimeout = $timeout ?? self::DEFAULT_AVAILABILITY_TIMEOUT;
        $cacheKey = $this->cacheKeyFactory->createAvailabilityKey(
            $this->apiClient->getApiBase(),
            $cacheType,
            $url,
            $effectiveTimeout
        );

        $cached = $this->cacheStore->get($cacheKey, $ttlSeconds);
        if ($cached !== null) {
            return $cached->value;
        }

        $result = $this->apiClient->checkAvailability($url, $effectiveTimeout);
        $this->cacheStore->put($cacheKey, $result);

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
