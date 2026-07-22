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
use Mata\Services\Monitor\Dto\MonitoringPolicy;
use Mata\Services\Monitor\Dto\ProbePolicy;
use Psr\Log\LoggerInterface;
use Throwable;

/** Application endpoint reachability monitoring with retry, persistent streaks, cache and event support. */
class AppReachabilityMonitor implements AppReachabilityMonitorInterface
{
    private const MAX_CONCURRENT_PROBES = 4;

    /** Probe half of {@see $policy}, cached so retry code reads like {@see NodeHealthMonitor}. */
    private readonly ProbePolicy $probePolicy;

    /**
     * @param null|Closure():int  $clock      current-time provider used by deterministic tests
     * @param null|Closure():void $retryDelay retry-wait hook used by deterministic tests
     */
    public function __construct(
        private CacheStoreInterface $cacheStore,
        private ApiCacheKeyFactory $cacheKeyFactory,
        private CacheTtlResolver $ttlResolver,
        private ApiClient $apiClient,
        private ProbeClient $probeClient,
        private EventManager $eventManager,
        private LoggerInterface $logger,
        private MonitoringPolicy $policy,
        private ?Closure $clock = null,
        private ?Closure $retryDelay = null,
    ) {
        $this->probePolicy = $policy->probe;
    }

    public function checkEndpoint(
        EndpointTarget $target,
        ?int $timeout = null,
        string $cacheType = CacheTypes::PING,
        bool $forceRefresh = false
    ): array {
        $timeout ??= $this->probePolicy->timeoutSeconds;
        $cacheKey = $this->availabilityCacheKey($target->resolveProbeUrl(), $cacheType, $timeout);
        $cached = $forceRefresh ? null : $this->cacheStore->get($cacheKey, $this->ttlResolver->get($cacheType));
        if ($cached !== null) {
            return $cached->value;
        }

        $result = $this->probeTargetsWithRetries([$target->identifier => $target], $timeout)[$target->identifier];
        $this->cacheStore->put($cacheKey, $result);
        $streak = $this->updateReachabilityStreak($target, $result);
        $this->recordUnreachableTarget($target, $result, $streak);

        return $result;
    }

    /** @param array<int|string,EndpointTarget> $targets
     * @return array<int|string,array<string,mixed>> */
    public function checkMultipleEndpoints(
        array $targets,
        ?int $timeout = null,
        string $cacheType = CacheTypes::PING,
        bool $forceRefresh = false
    ): array {
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
            $results += $this->probeTargetsWithRetries($batch, $timeout);
        }

        $ordered = [];
        $freshResults = [];
        foreach ($targets as $identifier => $target) {
            $fresh = array_key_exists($identifier, $cacheKeys);
            $result = $results[$identifier] ?? $this->unavailableResult($target, 'Availability probe did not return a result', $timeout);
            if ($fresh) {
                $this->cacheStore->put($cacheKeys[$identifier], $result);
                $freshResults[$identifier] = [
                    'target' => $target,
                    'result' => $result,
                    'streak' => $this->updateReachabilityStreak($target, $result),
                ];
            }
            $ordered[$identifier] = $result;
        }

        $server = $this->transportFailureServer($targets, $freshResults);
        if ($server !== null) {
            $this->recordServerAppsTransportFailure($server, $freshResults);
        } else {
            foreach ($freshResults as $freshResult) {
                $this->recordUnreachableTarget(
                    $freshResult['target'],
                    $freshResult['result'],
                    $freshResult['streak'],
                );
            }
        }

        return $ordered;
    }

    /** @param array<int|string,EndpointTarget> $targets
     * @return array<int|string,array<string,mixed>> */
    private function probeTargetsWithRetries(array $targets, int $timeout): array
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
     * @param array<int|string,EndpointTarget>      $targets
     * @param array<int|string,array<string,mixed>> $results
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
            ? $this->probeClient->probeReachabilityAsync($target->url, $timeout)
            : $this->probeClient->probeStatusEndpointAsync($target->statusUrl, $timeout);
    }

    /** @return array<string,mixed> */
    private function unavailableResult(EndpointTarget $target, string $error, int $timeout, int $attempts = 1): array
    {
        return [
            'reachable' => false,
            'httpCode' => null,
            'latency_ms' => null,
            'error' => $error !== '' ? $error : 'Availability probe failed',
            'failure_kind' => 'unknown',
            'curl_errno' => null,
            'timeout_ms' => $timeout * 1000,
            'attempts' => $attempts,
            'url' => $target->resolveProbeUrl(),
            'maintenance_mode' => false,
            'checked_at' => time(),
        ];
    }

    /** @param array<string,mixed> $result */
    private function shouldRetry(array $result): bool
    {
        if ($this->isReachableOrInMaintenance($result)) {
            return false;
        }

        return $this->probePolicy->isRetryable($result['failure_kind'] ?? null);
    }

    /** @param array<string,mixed> $result */
    private function isReachableOrInMaintenance(array $result): bool
    {
        $reachable = ($result['reachable'] ?? false) === true;
        $maintenanceMode = ($result['maintenance_mode'] ?? false) === true;

        return $reachable || $maintenanceMode;
    }

    private function availabilityCacheKey(string $url, string $cacheType, int $timeout): string
    {
        return $this->cacheKeyFactory->createAvailabilityKey($this->apiClient->getApiBase(), $cacheType, $url, $timeout);
    }

    /**
     * Return the shared server when every configured app was freshly checked
     * and failed at the transport layer. HTTP responses are app-level failures
     * and must remain separate.
     *
     * @param array<int|string,EndpointTarget>                                                                                        $targets
     * @param array<int|string,array{target:EndpointTarget,result:array<string,mixed>,streak:array{count:int,first_failure_at:?int}}> $freshResults
     */
    private function transportFailureServer(array $targets, array $freshResults): int|string|null
    {
        if (count($targets) < 2 || count($freshResults) !== count($targets)) {
            return null;
        }

        $server = null;
        foreach ($freshResults as $freshResult) {
            $target = $freshResult['target'];
            $result = $freshResult['result'];
            $targetServer = $target->serverId ?? $target->serverName;
            if ($targetServer === null || $targetServer === ''
                || ($server !== null && (string) $server !== (string) $targetServer)
                || $this->isReachableOrInMaintenance($result)
                || !$this->probePolicy->isRetryable($result['failure_kind'] ?? null)) {
                return null;
            }
            $server = $targetServer;
        }

        return $server;
    }

    /**
     * Record one server incident for a shared transport failure instead of an
     * identical alarm for each application.
     *
     * @param array<int|string,array{target:EndpointTarget,result:array<string,mixed>,streak:array{count:int,first_failure_at:?int}}> $freshResults
     */
    private function recordServerAppsTransportFailure(int|string $server, array $freshResults): void
    {
        $apps = [];
        $failureKinds = [];
        $attempts = 1;
        foreach ($freshResults as $freshResult) {
            $apps[] = $freshResult['target']->identifier;
            $failureKinds[] = $freshResult['result']['failure_kind'] ?? 'unknown';
            $attempts = max($attempts, (int) ($freshResult['result']['attempts'] ?? 1));
        }
        sort($apps);
        $failureKinds = array_values(array_unique($failureKinds));
        sort($failureKinds);

        $message = "All application probes failed at the transport layer for {$server}";
        $diagnostics = [
            'affected_apps' => $apps,
            'affected_app_count' => count($apps),
            'failure_kinds' => $failureKinds,
            'attempts' => $attempts,
            'checked_at' => gmdate(DATE_ATOM, $this->now()),
        ];
        $this->logger->warning("Ping -> {$message}", $diagnostics);
        $this->eventManager->handle(new EventCommand(
            type: 'server_apps_transport_unreachable',
            message: $message,
            level: 'critical',
            server: $server,
            context: ['scope' => 'all_app_transport'],
            metric: new EventMetric('application_transport_reachability', 0, additional: $diagnostics),
        ));
    }

    /**
     * Persist the consecutive failure streak for one endpoint.
     *
     * A reachable endpoint or an endpoint in maintenance clears its streak.
     * A failure outside the configured window starts a new streak.
     *
     * @param array<string,mixed> $result
     *
     * @return array{count:int,first_failure_at:?int}
     */
    private function updateReachabilityStreak(EndpointTarget $target, array $result): array
    {
        $cacheKey = $this->reachabilityStreakCacheKey($target);
        if ($this->isReachableOrInMaintenance($result)) {
            $this->cacheStore->delete($cacheKey);

            return ['count' => 0, 'first_failure_at' => null];
        }

        $now = $this->now();
        $entry = $this->cacheStore->get($cacheKey, -1);
        $storedStreak = $entry === null ? [] : $entry->value;
        $firstFailureAt = is_numeric($storedStreak['first_failure_at'] ?? null)
            ? (int) $storedStreak['first_failure_at']
            : $now;
        $failureCount = is_numeric($storedStreak['count'] ?? null) ? (int) $storedStreak['count'] : 0;

        if ($now - $firstFailureAt > $this->policy->unreachableWindowSeconds) {
            $firstFailureAt = $now;
            $failureCount = 0;
        }

        $streak = ['count' => $failureCount + 1, 'first_failure_at' => $firstFailureAt];
        $this->cacheStore->put($cacheKey, $streak);

        return $streak;
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

    private function reachabilityStreakCacheKey(EndpointTarget $target): string
    {
        $serverIdentity = $target->serverId ?? $target->serverName ?? '';
        $applicationIdentity = $target->applicationId ?? $target->applicationCode ?? $target->identifier;
        $endpointUrl = $target->resolveProbeUrl();
        $targetIdentity = implode('|', [$serverIdentity, $applicationIdentity, $endpointUrl]);
        $opaqueTargetIdentity = $this->cacheKeyFactory->createOpaqueLeaf($targetIdentity);

        return $this->cacheKeyFactory->createDashboardKey("reachability-streak/{$opaqueTargetIdentity}");
    }

    /** @param array<string,mixed> $result
     * @param array{count:int,first_failure_at:?int} $streak */
    private function recordUnreachableTarget(EndpointTarget $target, array $result, array $streak): void
    {
        if ($this->isReachableOrInMaintenance($result)) {
            return;
        }
        $server = $target->serverId ?? $target->serverName;
        $app = $target->applicationId ?? $target->applicationCode;
        $url = $target->resolveProbeUrl();
        $message = "No response from {$target->identifier} ({$url})";
        $diagnostics = [
            'failure_kind' => $result['failure_kind'] ?? 'unknown',
            'http_code' => $result['httpCode'] ?? null,
            'curl_errno' => $result['curl_errno'] ?? null,
            'timeout_ms' => $result['timeout_ms'] ?? $this->probePolicy->timeoutSeconds * 1000,
            'attempts' => $result['attempts'] ?? 1,
            'error' => $result['error'] ?? null,
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
