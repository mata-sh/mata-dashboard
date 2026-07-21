<?php

declare(strict_types=1);

namespace Mata\Services\Monitor;

use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use Mata\Constants\CacheTypes;
use Mata\Services\Cache\ApiCacheKeyFactory;
use Mata\Services\Cache\CacheStoreInterface;
use Mata\Services\Cache\CacheTtlResolver;
use Mata\Services\Event\Dto\EventCommand;
use Mata\Services\Event\Dto\EventMetric;
use Mata\Services\Event\EventManager;
use Mata\Services\Foundation\ApiClient;
use Mata\Services\Foundation\ProbeClient;
use Mata\Services\Monitor\Dto\EndpointTarget;
use Mata\Services\Monitor\Dto\ProbePolicy;
use Psr\Log\LoggerInterface;
use Throwable;

/** Application availability monitoring with retry, persistent streaks, cache and event support. */
class AppMonitor implements AppMonitorInterface
{
    private const MAX_CONCURRENT_PROBES = 4;

    private ProbePolicy $probePolicy;

    public function __construct(
        private CacheStoreInterface $cacheStore,
        private ApiCacheKeyFactory $cacheKeyFactory,
        private CacheTtlResolver $ttlResolver,
        private ApiClient $apiClient,
        private ProbeClient $probeClient,
        private EventManager $eventManager,
        private LoggerInterface $logger,
        ?ProbePolicy $probePolicy = null,
        private int $unreachableWindowSeconds = 600,
        private ?Closure $clock = null,
        private ?Closure $retryDelay = null,
    ) {
        $this->probePolicy = $probePolicy ?? ProbePolicy::default();
    }

    public function checkEndpoint(EndpointTarget $target, ?int $timeout = null, string $cacheType = CacheTypes::PING, bool $forceRefresh = false): array
    {
        $timeout ??= $this->probePolicy->timeoutSeconds;
        $cacheKey = $this->availabilityCacheKey($target->resolveProbeUrl(), $cacheType, $timeout);
        $cached = $forceRefresh ? null : $this->cacheStore->get($cacheKey, $this->ttlResolver->get($cacheType));
        if ($cached !== null) {
            return $cached->value;
        }

        $result = $this->probeCycle([$target->identifier => $target], $timeout)[$target->identifier];
        $this->cacheStore->put($cacheKey, $result);
        $streak = $this->updateStreak($target, $result);
        $this->recordUnreachableTarget($target, $result, $streak);

        return $result;
    }

    /** @param array<int|string,EndpointTarget> $targets
     * @return array<int|string,array<string,mixed>> */
    public function checkMultipleEndpoints(array $targets, ?int $timeout = null, string $cacheType = CacheTypes::PING, bool $forceRefresh = false): array
    {
        if ($targets === []) {
            return [];
        }

        $timeout ??= $this->probePolicy->timeoutSeconds;
        $ttlSeconds = $this->ttlResolver->get($cacheType);
        $results = [];
        $cacheKeys = [];
        $uncached = [];
        foreach ($targets as $identifier => $target) {
            $cacheKey = $this->availabilityCacheKey($target->resolveProbeUrl(), $cacheType, $timeout);
            $cached = $forceRefresh ? null : $this->cacheStore->get($cacheKey, $ttlSeconds);
            if ($cached !== null) {
                $results[$identifier] = $cached->value;

                continue;
            }
            $cacheKeys[$identifier] = $cacheKey;
            $uncached[$identifier] = $target;
        }

        foreach (array_chunk($uncached, self::MAX_CONCURRENT_PROBES, true) as $batch) {
            $results += $this->probeCycle($batch, $timeout);
        }

        $ordered = [];
        foreach ($targets as $identifier => $target) {
            $fresh = array_key_exists($identifier, $cacheKeys);
            $result = $results[$identifier] ?? $this->unavailableResult($target, 'Availability probe did not return a result', $timeout);
            if ($fresh) {
                $this->cacheStore->put($cacheKeys[$identifier], $result);
                $streak = $this->updateStreak($target, $result);
                $this->recordUnreachableTarget($target, $result, $streak);
            }
            $ordered[$identifier] = $result;
        }

        return $ordered;
    }

    /** @param array<int|string,EndpointTarget> $targets
     * @return array<int|string,array<string,mixed>> */
    private function probeCycle(array $targets, int $timeout): array
    {
        $results = $this->probeBatch($targets, $timeout, 1);
        $retry = $this->retryableTargets($targets, $results);

        for ($attempt = 1; $attempt <= $this->probePolicy->maxRetries && $retry !== []; ++$attempt) {
            $this->waitForRetry($this->probePolicy->backoffMicros($attempt));
            $attemptNumber = $attempt + 1;
            $retryResults = $this->probeBatch($retry, $timeout, $attemptNumber);
            foreach ($retry as $identifier => $target) {
                $results[$identifier] = $retryResults[$identifier];
            }
            $retry = $this->retryableTargets($retry, $results);
        }

        return $results;
    }

    /**
     * Probe a set of targets concurrently, stamping the attempt number and filling any gaps.
     *
     * @param array<int|string,EndpointTarget> $targets
     *
     * @return array<int|string,array<string,mixed>>
     */
    private function probeBatch(array $targets, int $timeout, int $attemptNumber): array
    {
        $pending = [];
        $results = [];
        foreach ($targets as $identifier => $target) {
            try {
                $pending[$identifier] = $this->probeTargetAsync($target, $timeout);
            } catch (Throwable $exception) {
                $results[$identifier] = $this->unavailableResult($target, $exception->getMessage(), $timeout, $attemptNumber);
            }
        }
        foreach ($this->probeClient->settle($pending) as $identifier => $result) {
            $result['attempts'] = $attemptNumber;
            $results[$identifier] = $result;
        }
        foreach ($targets as $identifier => $target) {
            if (!isset($results[$identifier]) || !is_array($results[$identifier])) {
                $results[$identifier] = $this->unavailableResult($target, 'Availability probe did not return a result', $timeout, $attemptNumber);
            }
        }

        return $results;
    }

    /**
     * @param array<int|string,EndpointTarget>        $targets
     * @param array<int|string,array<string,mixed>>   $results
     *
     * @return array<int|string,EndpointTarget>
     */
    private function retryableTargets(array $targets, array $results): array
    {
        $retry = [];
        foreach ($targets as $identifier => $target) {
            if ($this->shouldRetry($results[$identifier] ?? [])) {
                $retry[$identifier] = $target;
            }
        }

        return $retry;
    }

    private function probeTargetAsync(EndpointTarget $target, int $timeout): PromiseInterface
    {
        return $target->statusUrl === null
            ? $this->probeClient->checkAvailabilityAsync($target->url, $timeout)
            : $this->probeClient->checkStatusAsync($target->statusUrl, $timeout);
    }

    /** @return array<string,mixed> */
    private function unavailableResult(EndpointTarget $target, string $error, int $timeout, int $attempts = 1): array
    {
        return ['reachable' => false, 'httpCode' => null, 'latency_ms' => null,
            'error' => $error !== '' ? $error : 'Availability probe failed', 'failure_kind' => 'unknown',
            'curl_errno' => null, 'timeout_ms' => $timeout * 1000, 'attempts' => $attempts,
            'url' => $target->resolveProbeUrl(), 'maintenance_mode' => false, 'checked_at' => time()];
    }

    private function shouldRetry(array $result): bool
    {
        return !($result['reachable'] ?? false)
            && ($result['maintenance_mode'] ?? false) !== true
            && $this->probePolicy->isRetryable($result['failure_kind'] ?? null);
    }

    private function availabilityCacheKey(string $url, string $cacheType, int $timeout): string
    {
        return $this->cacheKeyFactory->createAvailabilityKey($this->apiClient->getApiBase(), $cacheType, $url, $timeout);
    }

    /** @return array{count:int,first_failure_at:?int} */
    private function updateStreak(EndpointTarget $target, array $result): array
    {
        $key = $this->streakKey($target);
        if (($result['reachable'] ?? false) || ($result['maintenance_mode'] ?? false) === true) {
            $this->cacheStore->delete($key);

            return ['count' => 0, 'first_failure_at' => null];
        }
        $now = $this->now();
        $entry = $this->cacheStore->get($key, -1);
        $existing = $entry === null ? [] : $entry->value;
        $first = is_numeric($existing['first_failure_at'] ?? null) ? (int) $existing['first_failure_at'] : $now;
        $count = is_numeric($existing['count'] ?? null) ? (int) $existing['count'] : 0;
        if ($now - $first > $this->unreachableWindowSeconds) {
            $first = $now;
            $count = 0;
        }
        $state = ['count' => $count + 1, 'first_failure_at' => $first];
        $this->cacheStore->put($key, $state);

        return $state;
    }

    private function now(): int
    {
        return $this->clock === null ? time() : (int) ($this->clock)();
    }

    private function waitForRetry(int $micros): void
    {
        if ($this->retryDelay !== null) {
            ($this->retryDelay)();

            return;
        }
        if ($micros > 0) {
            usleep($micros);
        }
    }

    private function streakKey(EndpointTarget $target): string
    {
        return $this->cacheKeyFactory->createDashboardKey('reachability-streak/' . $this->cacheKeyFactory->createOpaqueLeaf(implode('|', [
            $target->serverId ?? $target->serverName ?? '', $target->applicationId ?? $target->applicationCode ?? $target->identifier,
            $target->resolveProbeUrl(),
        ])));
    }

    /** @param array<string,mixed> $result
     * @param array{count:int,first_failure_at:?int} $streak */
    private function recordUnreachableTarget(EndpointTarget $target, array $result, array $streak): void
    {
        if (($result['reachable'] ?? false) || ($result['maintenance_mode'] ?? false) === true) {
            return;
        }
        $server = $target->serverId ?? $target->serverName;
        $app = $target->applicationId ?? $target->applicationCode;
        $url = $target->resolveProbeUrl();
        $message = "No response from {$target->identifier} ({$url})";
        $diagnostics = [
            'failure_kind' => $result['failure_kind'] ?? 'unknown', 'http_code' => $result['httpCode'] ?? null,
            'curl_errno' => $result['curl_errno'] ?? null, 'timeout_ms' => $result['timeout_ms'] ?? $this->probePolicy->timeoutSeconds * 1000,
            'attempts' => $result['attempts'] ?? 1, 'error' => $result['error'] ?? null,
            'consecutive_failures' => $streak['count'],
            'first_failure_at' => $streak['first_failure_at'] === null ? null : gmdate(DATE_ATOM, $streak['first_failure_at']),
            'checked_at' => isset($result['checked_at']) ? gmdate(DATE_ATOM, (int) $result['checked_at']) : gmdate(DATE_ATOM, $this->now()),
        ];
        $this->logger->warning("Ping -> {$message}", $diagnostics);
        $this->eventManager->handle(new EventCommand(
            type: 'app_unreachable',
            message: $message,
            level: 'critical',
            server: $server,
            app: $app,
            context: ['identifier' => $target->identifier, 'url' => $url],
            metric: new EventMetric('endpoint_reachability', 0, additional: $diagnostics),
        ));
    }
}
