<?php

declare(strict_types=1);

namespace Mata\Services\Monitor;

use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use Mata\Services\Cache\ApiCacheKeyFactory;
use Mata\Services\Cache\CacheStoreInterface;
use Mata\Services\Cache\CacheTtlResolver;
use Mata\Services\Cache\CacheType;
use Mata\Services\Collect\NodeApiClient;
use Mata\Services\Event\Dto\EventCommand;
use Mata\Services\Event\Dto\EventMetric;
use Mata\Services\Event\EventManager;
use Mata\Services\Monitor\Dto\AppMonitoringSettings;
use Mata\Services\Monitor\Dto\AppReachabilityPolicy;
use Mata\Services\Monitor\Dto\EndpointProbeResult;
use Mata\Services\Monitor\Dto\EndpointTarget;
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
        private NodeApiClient $apiClient,
        private HttpProbeClient $probeClient,
        private EventManager $eventManager,
        private LoggerInterface $logger,
        private AppReachabilityPolicy $policy,
        private ?Closure $clock = null,
        private ?Closure $retryDelay = null,
    ) {
        $this->probePolicy = $policy->probe;
    }

    public function checkEndpoint(
        EndpointTarget $target,
        ?int $timeout = null,
        CacheType $cacheType = CacheType::Ping,
        bool $forceRefresh = false
    ): EndpointProbeResult {
        $timeout ??= $this->probePolicy->timeoutSeconds;
        $cacheKey = $this->availabilityCacheKey($target->resolveProbeUrl(), $cacheType, $timeout);
        $cached = $forceRefresh ? null : $this->cacheStore->get($cacheKey, $this->ttlResolver->get($cacheType));
        if ($cached !== null) {
            return EndpointProbeResult::fromArray($cached->value);
        }

        $result = $this->probeTargetsWithRetries([$target->identifier => $target], $timeout)[$target->identifier];
        $this->cacheStore->put($cacheKey, $result->toArray());
        $streak = $this->updateReachabilityStreak($target, $result);
        $this->recordUnreachableTarget($target, $result, $streak);

        return $result;
    }

    /**
     * @param array<int|string,EndpointTarget> $targets
     *
     * @return array<int|string,EndpointProbeResult>
     */
    public function checkMultipleEndpoints(
        array $targets,
        ?int $timeout = null,
        CacheType $cacheType = CacheType::Ping,
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
                $results[$identifier] = EndpointProbeResult::fromArray($cached->value);

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
                $this->cacheStore->put($cacheKeys[$identifier], $result->toArray());
                $freshResults[$identifier] = [
                    'target' => $target,
                    'result' => $result,
                    'streak' => $this->updateReachabilityStreak($target, $result),
                ];
            }
            $ordered[$identifier] = $result;
        }

        // Event-disabled apps do not participate in the transport quorum.
        $eventKeys = $this->eventEnabledKeys($targets);
        $eventFreshResults = array_intersect_key($freshResults, $eventKeys);
        $server = $this->transportFailureServer(array_intersect_key($targets, $eventKeys), $eventFreshResults);
        if ($server !== null) {
            $this->recordServerAppsTransportFailure($server, $eventFreshResults);
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

    /**
     * @param array<int|string,EndpointTarget> $targets
     *
     * @return array<int|string,EndpointProbeResult>
     */
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
     * @return array<int|string,EndpointProbeResult>
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
            $results[$identifier] = EndpointProbeResult::fromArray($result)->withAttempts($attemptNumber);
        }
        foreach ($targets as $identifier => $target) {
            if (!isset($results[$identifier])) {
                $results[$identifier] = $this->unavailableResult($target, 'Availability probe did not return a result', $timeout, $attemptNumber);
            }
        }

        return $results;
    }

    /**
     * @param array<int|string,EndpointTarget>      $targets
     * @param array<int|string,EndpointProbeResult> $results
     *
     * @return array<int|string,EndpointTarget>
     */
    private function retryableTargets(array $targets, array $results): array
    {
        $retry = [];
        foreach ($targets as $identifier => $target) {
            $result = $results[$identifier] ?? null;
            if ($result instanceof EndpointProbeResult && $this->shouldRetry($result)) {
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

    private function unavailableResult(EndpointTarget $target, string $error, int $timeout, int $attempts = 1): EndpointProbeResult
    {
        return EndpointProbeResult::fromArray([
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
        ]);
    }

    private function shouldRetry(EndpointProbeResult $result): bool
    {
        if ($this->isReachableOrInMaintenance($result)) {
            return false;
        }

        return $this->probePolicy->isRetryable($result->failureKind);
    }

    private function isReachableOrInMaintenance(EndpointProbeResult $result): bool
    {
        return $result->reachable || $result->maintenanceMode;
    }

    private function availabilityCacheKey(string $url, CacheType $cacheType, int $timeout): string
    {
        return $this->cacheKeyFactory->createAvailabilityKey($this->apiClient->getApiBase(), $cacheType, $url, $timeout);
    }

    /**
     * Return the shared server when every configured app was freshly checked
     * and failed at the transport layer. HTTP responses are app-level failures
     * and must remain separate.
     *
     * @param array<int|string,EndpointTarget>                                                                                        $targets
     * @param array<int|string,array{target:EndpointTarget,result:EndpointProbeResult,streak:array{count:int,first_failure_at:?int}}> $freshResults
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
                || !$this->probePolicy->isRetryable($result->failureKind)) {
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
     * @param array<int|string,array{target:EndpointTarget,result:EndpointProbeResult,streak:array{count:int,first_failure_at:?int}}> $freshResults
     */
    private function recordServerAppsTransportFailure(int|string $server, array $freshResults): void
    {
        $apps = [];
        $failureKinds = [];
        $attempts = 1;
        foreach ($freshResults as $freshResult) {
            $apps[] = $freshResult['target']->identifier;
            $failureKinds[] = $freshResult['result']->failureKind ?? 'unknown';
            $attempts = max($attempts, $freshResult['result']->attempts);
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
     * @return array{count:int,first_failure_at:?int}
     */
    private function updateReachabilityStreak(EndpointTarget $target, EndpointProbeResult $result): array
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

    /** Resolve the target's fail-open monitoring settings. */
    private function monitoringSettings(EndpointTarget $target): AppMonitoringSettings
    {
        return AppMonitoringSettings::fromAppPayload($target->metadata, $this->logger);
    }

    /**
     * @param array<int|string,EndpointTarget> $targets
     *
     * @return array<int|string,true>
     */
    private function eventEnabledKeys(array $targets): array
    {
        $keys = [];
        foreach ($targets as $identifier => $target) {
            if ($this->monitoringSettings($target)->isEnabled('events')) {
                $keys[$identifier] = true;
            }
        }

        return $keys;
    }

    /** @param array{count:int,first_failure_at:?int} $streak */
    private function recordUnreachableTarget(EndpointTarget $target, EndpointProbeResult $result, array $streak): void
    {
        if ($this->isReachableOrInMaintenance($result)) {
            return;
        }
        $monitoring = $this->monitoringSettings($target);
        if (!$monitoring->isEnabled('events')) {
            return;
        }
        $server = $target->serverId ?? $target->serverName;
        $app = $target->applicationId ?? $target->applicationCode;
        $url = $target->resolveProbeUrl();
        $message = "No response from {$target->identifier} ({$url})";
        $diagnostics = [
            'failure_kind' => $result->failureKind ?? 'unknown',
            'http_code' => $result->httpCode,
            'curl_errno' => $result->curlErrno,
            'timeout_ms' => $result->timeoutMs,
            'attempts' => $result->attempts,
            'error' => $result->error,
            'consecutive_failures' => $streak['count'],
            'first_failure_at' => $streak['first_failure_at'] === null ? null : gmdate(DATE_ATOM, $streak['first_failure_at']),
            'checked_at' => gmdate(DATE_ATOM, $result->checkedAt),
        ];
        $this->logger->warning("Ping -> {$message}", $diagnostics);
        $this->eventManager->handle(new EventCommand(
            type: 'app_unreachable',
            message: $message,
            level: 'critical',
            server: $server,
            app: $app,
            context: $monitoring->isEnabled('alarms')
                ? ['identifier' => $target->identifier, 'url' => $url]
                // Keep the event; AlarmDecisionProcessor suppresses its alarm.
                : ['identifier' => $target->identifier, 'url' => $url, 'alarms_suppressed' => true],
            metric: new EventMetric('endpoint_reachability', 0, additional: $diagnostics),
        ));
    }
}
