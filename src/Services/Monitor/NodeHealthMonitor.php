<?php

declare(strict_types=1);

namespace Mata\Services\Monitor;

use Mata\Constants\CacheTypes;
use Mata\Services\Cache\ApiCacheKeyFactory;
use Mata\Services\Cache\CacheStoreInterface;
use Mata\Services\Cache\CacheTtlResolver;
use Mata\Services\Event\EventManager;
use Mata\Services\Foundation\ApiClient;
use Psr\Log\LoggerInterface;

/**
 * Monitors the mata-node health endpoint with cache and event support.
 */
class NodeHealthMonitor implements NodeHealthMonitorInterface
{
    public function __construct(
        private CacheStoreInterface $cacheStore,
        private ApiCacheKeyFactory $cacheKeyFactory,
        private CacheTtlResolver $ttlResolver,
        private ApiClient $apiClient,
        private EventManager $eventManager,
        private LoggerInterface $logger
    ) {}

    public function check(string $serverName, string $cacheType = CacheTypes::HEALTH): array
    {
        $ttlSeconds = $this->ttlResolver->get($cacheType);
        $cacheKey = $this->cacheKeyFactory->createApiRequestKey(
            $this->apiClient->getApiBase(),
            'GET',
            'health'
        );
        $cached = $this->cacheStore->get($cacheKey, $ttlSeconds);

        if ($cached !== null) {
            return $cached->value;
        }

        $result = $this->apiClient->getHealth();
        $result['state'] = NodeHealthStateResolver::resolve($result);
        $this->cacheStore->put($cacheKey, $result);
        $this->recordHealthEvent($serverName, $result);

        return $result;
    }

    /**
     * @param array<string,mixed> $result
     */
    private function recordHealthEvent(string $serverName, array $result): void
    {
        $status = $result['status'] ?? 'unreachable';
        if ($status === 'healthy') {
            return;
        }

        $isUnreachable = $status === 'unreachable';
        $type = $isUnreachable ? 'node_unreachable' : 'node_unhealthy';
        $level = $isUnreachable ? 'critical' : 'warning';
        $message = $isUnreachable
            ? "No health response from {$serverName}"
            : "Unhealthy health response from {$serverName}";

        $this->logger->warning("Node health -> {$message}", [
            'httpCode' => $result['httpCode'] ?? null,
            'error' => $result['error'] ?? null,
        ]);
        $this->eventManager->handleEvent(
            type: $type,
            message: $message,
            context: ['server' => $serverName],
            level: $level,
            server: $serverName
        );
    }
}
