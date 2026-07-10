<?php

declare(strict_types=1);

namespace Mata\Services\Monitor;

use Mata\Constants\CacheTypes;
use Mata\Services\Cache\ApiCacheKeyFactory;
use Mata\Services\Cache\CacheStoreInterface;
use Mata\Services\Cache\CacheTtlResolver;
use Mata\Services\Event\EventManager;
use Mata\Services\Foundation\ApiClient;
use Mata\Services\Foundation\ProbeClient;
use Mata\Services\Monitor\Dto\EndpointTarget;
use Psr\Log\LoggerInterface;

/**
 * Application availability monitoring with cache and event support.
 */
class AppMonitor implements AppMonitorInterface
{
    public function __construct(
        private CacheStoreInterface $cacheStore,
        private ApiCacheKeyFactory $cacheKeyFactory,
        private CacheTtlResolver $ttlResolver,
        private ApiClient $apiClient,
        private ProbeClient $probeClient,
        private EventManager $eventManager,
        private LoggerInterface $logger
    ) {}

    public function checkEndpoint(EndpointTarget $target, int $timeout = 2, string $cacheType = CacheTypes::PING): array
    {
        $cacheKey = $this->availabilityCacheKey($target->url, $cacheType, $timeout);
        $cached = $this->cacheStore->get($cacheKey, $this->ttlResolver->get($cacheType));
        if ($cached !== null) {
            return $cached->value;
        }

        /** @var array<string,mixed> $result */
        $result = $this->probeClient->checkAvailabilityAsync($target->url, $timeout)->wait();
        $this->cacheStore->put($cacheKey, $result);

        return $result;
    }

    /**
     * @param array<int|string,EndpointTarget> $targets
     *
     * @return array<int|string,array<string,mixed>>
     */
    public function checkMultipleEndpoints(array $targets, int $timeout = 2, string $cacheType = CacheTypes::PING): array
    {
        if ($targets === []) {
            return [];
        }

        $ttlSeconds = $this->ttlResolver->get($cacheType);
        $results = [];
        $cacheKeys = [];
        $pending = [];

        foreach ($targets as $identifier => $target) {
            $cacheKey = $this->availabilityCacheKey($target->url, $cacheType, $timeout);
            $cached = $this->cacheStore->get($cacheKey, $ttlSeconds);
            if ($cached !== null) {
                $results[$identifier] = $cached->value;

                continue;
            }

            $cacheKeys[$identifier] = $cacheKey;
            $pending[$identifier] = $this->probeClient->checkAvailabilityAsync($target->url, $timeout);
        }

        foreach ($this->probeClient->settle($pending) as $identifier => $result) {
            $this->cacheStore->put($cacheKeys[$identifier], $result);
            $results[$identifier] = $result;
        }

        $orderedResults = [];
        foreach ($targets as $identifier => $target) {
            $result = $results[$identifier];
            $orderedResults[$identifier] = $result;
            $this->recordUnreachableTarget($target, $result);
        }

        return $orderedResults;
    }

    private function availabilityCacheKey(string $url, string $cacheType, int $timeout): string
    {
        return $this->cacheKeyFactory->createAvailabilityKey(
            $this->apiClient->getApiBase(),
            $cacheType,
            $url,
            $timeout
        );
    }

    /**
     * @param array<string,mixed> $result
     */
    private function recordUnreachableTarget(EndpointTarget $target, array $result): void
    {
        if ($result['reachable'] ?? false) {
            return;
        }

        $ctx = [
            'identifier' => $target->identifier,
            'url' => $target->url,
            'server' => $target->serverId ?? $target->serverName,
            'app' => $target->applicationId,
        ];
        $message = "No response from {$ctx['identifier']} ({$ctx['url']})";

        $this->logger->warning("Ping -> {$message}", [
            'httpCode' => $result['httpCode'] ?? null,
            'error' => $result['error'] ?? null,
        ]);
        $this->eventManager->handleEvent(
            type: 'app_unreachable',
            message: $message,
            context: ['identifier' => $ctx['identifier'], 'url' => $ctx['url']],
            level: 'critical',
            server: $ctx['server'],
            app: $ctx['app']
        );
    }
}
