<?php

declare(strict_types=1);

namespace Mata\Services\Monitor;

use GuzzleHttp\Promise\PromiseInterface;
use Mata\Constants\CacheTypes;
use Mata\Services\Cache\ApiCacheKeyFactory;
use Mata\Services\Cache\CacheStoreInterface;
use Mata\Services\Cache\CacheTtlResolver;
use Mata\Services\Event\EventManager;
use Mata\Services\Foundation\ApiClient;
use Mata\Services\Foundation\ProbeClient;
use Psr\Log\LoggerInterface;

/**
 * Monitors the mata-node health endpoint with cache and event support.
 */
class NodeHealthMonitor implements NodeHealthMonitorInterface
{
    private const HEALTH_TIMEOUT = 3;

    public function __construct(
        private CacheStoreInterface $cacheStore,
        private ApiCacheKeyFactory $cacheKeyFactory,
        private CacheTtlResolver $ttlResolver,
        private ApiClient $apiClient,
        private ProbeClient $probeClient,
        private EventManager $eventManager,
        private LoggerInterface $logger
    ) {}

    public function checkOrWait(
        string $serverName,
        string $cacheType = CacheTypes::HEALTH,
        bool $forceRefresh = false,
    ): array {
        $result = $this->checkAsync($serverName, $cacheType, $forceRefresh);

        // @var array<string,mixed> $result
        return is_array($result) ? $result : $result->wait();
    }

    public function checkAsync(
        string $serverName,
        string $cacheType = CacheTypes::HEALTH,
        bool $forceRefresh = false,
    ): array|PromiseInterface {
        $cacheKey = $this->cacheKeyFactory->createApiRequestKey(
            $this->apiClient->getApiBase(),
            'GET',
            'health'
        );
        $cached = $forceRefresh ? null : $this->cacheStore->get($cacheKey, $this->ttlResolver->get($cacheType));
        if ($cached !== null) {
            return $cached->value;
        }

        $healthUrl = rtrim($this->apiClient->getApiBase(), '/') . '/health';
        $healthPromise = $this->probeClient->checkHealthAsync($healthUrl, self::HEALTH_TIMEOUT);

        // Cache and emit events after the health probe resolves.
        return $healthPromise->then(
            fn (array $health): array => $this->completeCheck($serverName, $cacheKey, $health)
        );
    }

    /**
     * @param array<string,mixed> $result
     *
     * @return array<string,mixed>
     */
    private function completeCheck(string $serverName, string $cacheKey, array $result): array
    {
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
