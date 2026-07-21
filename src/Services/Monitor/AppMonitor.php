<?php

declare(strict_types=1);

namespace Mata\Services\Monitor;

use GuzzleHttp\Promise\PromiseInterface;
use Mata\Constants\CacheTypes;
use Mata\Services\Cache\ApiCacheKeyFactory;
use Mata\Services\Cache\CacheStoreInterface;
use Mata\Services\Cache\CacheTtlResolver;
use Mata\Services\Event\Dto\EventCommand;
use Mata\Services\Event\EventManager;
use Mata\Services\Foundation\ApiClient;
use Mata\Services\Foundation\ProbeClient;
use Mata\Services\Monitor\Dto\EndpointTarget;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Application availability monitoring with cache and event support.
 */
class AppMonitor implements AppMonitorInterface
{
    /** Keep shared hosts responsive without serializing application checks. */
    private const MAX_CONCURRENT_PROBES = 4;

    public function __construct(
        private CacheStoreInterface $cacheStore,
        private ApiCacheKeyFactory $cacheKeyFactory,
        private CacheTtlResolver $ttlResolver,
        private ApiClient $apiClient,
        private ProbeClient $probeClient,
        private EventManager $eventManager,
        private LoggerInterface $logger
    ) {}

    public function checkEndpoint(
        EndpointTarget $target,
        int $timeout = 2,
        string $cacheType = CacheTypes::PING,
        bool $forceRefresh = false,
    ): array {
        $cacheKey = $this->availabilityCacheKey($target->resolveProbeUrl(), $cacheType, $timeout);
        $cached = $forceRefresh ? null : $this->cacheStore->get($cacheKey, $this->ttlResolver->get($cacheType));
        if ($cached !== null) {
            return $cached->value;
        }

        /** @var array<string,mixed> $result */
        $result = $this->probeTargetAsync($target, $timeout)->wait();
        $this->cacheStore->put($cacheKey, $result);

        return $result;
    }

    /**
     * @param array<int|string,EndpointTarget> $targets
     *
     * @return array<int|string,array<string,mixed>>
     */
    public function checkMultipleEndpoints(
        array $targets,
        int $timeout = 2,
        string $cacheType = CacheTypes::PING,
        bool $forceRefresh = false,
    ): array {
        if ($targets === []) {
            return [];
        }

        $ttlSeconds = $this->ttlResolver->get($cacheType);
        $results = [];
        $cacheKeys = [];
        $uncachedTargets = [];

        foreach ($targets as $identifier => $target) {
            $cacheKey = $this->availabilityCacheKey($target->resolveProbeUrl(), $cacheType, $timeout);
            $cached = $forceRefresh ? null : $this->cacheStore->get($cacheKey, $ttlSeconds);
            if ($cached !== null) {
                $results[$identifier] = $cached->value;

                continue;
            }

            $cacheKeys[$identifier] = $cacheKey;
            $uncachedTargets[$identifier] = $target;
        }

        $targetBatches = array_chunk($uncachedTargets, self::MAX_CONCURRENT_PROBES, true);
        foreach ($targetBatches as $targetBatch) {
            $pending = [];
            foreach ($targetBatch as $identifier => $target) {
                try {
                    $pending[$identifier] = $this->probeTargetAsync($target, $timeout);
                } catch (Throwable $exception) {
                    $results[$identifier] = $this->unavailableResult($target, $exception->getMessage());
                }
            }

            foreach ($this->probeClient->settle($pending) as $identifier => $result) {
                $results[$identifier] = $result;
            }
        }

        $orderedResults = [];
        foreach ($targets as $identifier => $target) {
            $result = $results[$identifier] ?? $this->unavailableResult($target, 'Availability probe did not return a result');
            if (array_key_exists($identifier, $cacheKeys)) {
                $this->cacheStore->put($cacheKeys[$identifier], $result);
            }
            $orderedResults[$identifier] = $result;
            $this->recordUnreachableTarget($target, $result);
        }

        return $orderedResults;
    }

    private function probeTargetAsync(EndpointTarget $target, int $timeout): PromiseInterface
    {
        return $target->statusUrl === null
            ? $this->probeClient->checkAvailabilityAsync($target->url, $timeout)
            : $this->probeClient->checkStatusAsync($target->statusUrl, $timeout);
    }

    /**
     * @return array{reachable:false,httpCode:null,latency_ms:null,error:string,url:string,maintenance_mode:false,checked_at:int}
     */
    private function unavailableResult(EndpointTarget $target, string $error): array
    {
        return [
            'reachable' => false,
            'httpCode' => null,
            'latency_ms' => null,
            'error' => $error !== '' ? $error : 'Availability probe failed',
            'url' => $target->resolveProbeUrl(),
            'maintenance_mode' => false,
            'checked_at' => time(),
        ];
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
        if (($result['reachable'] ?? false) || ($result['maintenance_mode'] ?? false) === true) {
            return;
        }

        $ctx = [
            'identifier' => $target->identifier,
            'url' => $target->resolveProbeUrl(),
            'server' => $target->serverId ?? $target->serverName,
            'app' => $target->applicationId ?? $target->applicationCode,
        ];
        $message = "No response from {$ctx['identifier']} ({$ctx['url']})";

        $this->logger->warning("Ping -> {$message}", [
            'httpCode' => $result['httpCode'] ?? null,
            'error' => $result['error'] ?? null,
        ]);
        $this->eventManager->handle(new EventCommand(
            type: 'app_unreachable',
            message: $message,
            level: 'critical',
            server: $ctx['server'],
            app: $ctx['app'],
            context: [
                'identifier' => $ctx['identifier'],
                'url' => $ctx['url'],
            ]
        ));
    }
}
