<?php

declare(strict_types=1);

namespace Mata\Services\Monitor;

use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use Mata\Constants\CacheTypes;
use Mata\Constants\NodeHealthState;
use Mata\Services\Cache\ApiCacheKeyFactory;
use Mata\Services\Cache\CacheStoreInterface;
use Mata\Services\Cache\CacheTtlResolver;
use Mata\Services\Event\Dto\EventCommand;
use Mata\Services\Event\Dto\EventMetric;
use Mata\Services\Event\EventManager;
use Mata\Services\Foundation\ApiClient;
use Mata\Services\Foundation\ProbeClient;
use Mata\Services\Monitor\Dto\ProbePolicy;
use Psr\Log\LoggerInterface;

/**
 * Monitors the mata-node health endpoint with transient transport retry support.
 *
 * @phpstan-type HealthProbeResult array{
 *     status: NodeHealthState::HEALTHY|NodeHealthState::UNHEALTHY|NodeHealthState::UNREACHABLE,
 *     httpCode: ?int,
 *     maintenance_mode: ?bool,
 *     checks: ?array<string,mixed>,
 *     node_timestamp: mixed,
 *     latency_ms: ?int,
 *     error: ?string,
 *     failure_kind: ?string,
 *     curl_errno: ?int,
 *     timeout_ms: int,
 *     attempts: int,
 *     checked_at: int,
 *     state?: string
 * }
 */
class NodeHealthMonitor implements NodeHealthMonitorInterface
{
    private readonly ProbePolicy $probePolicy;

    /** @param Closure(int):void|null $retryDelay Retry-delay hook used by deterministic tests. */
    public function __construct(
        private CacheStoreInterface $cacheStore,
        private ApiCacheKeyFactory $cacheKeyFactory,
        private CacheTtlResolver $ttlResolver,
        private ApiClient $apiClient,
        private ProbeClient $probeClient,
        private EventManager $eventManager,
        private LoggerInterface $logger,
        ?ProbePolicy $probePolicy = null,
        private ?Closure $retryDelay = null,
    ) {
        $this->probePolicy = $probePolicy ?? ProbePolicy::default();
    }

    /** @return HealthProbeResult */
    public function checkSynchronously(
        string $serverName,
        string $cacheTtlType = CacheTypes::HEALTH,
        bool $forceRefresh = false,
    ): array {
        $result = $this->checkAsync($serverName, $cacheTtlType, $forceRefresh);

        return is_array($result) ? $result : $result->wait();
    }

    /** @return HealthProbeResult|PromiseInterface */
    public function checkAsync(
        string $serverName,
        string $cacheTtlType = CacheTypes::HEALTH,
        bool $forceRefresh = false,
    ): array|PromiseInterface {
        $cacheKey = $this->healthCacheKey();
        $cacheEntry = $forceRefresh
            ? null
            : $this->cacheStore->get($cacheKey, $this->ttlResolver->get($cacheTtlType));
        if ($cacheEntry !== null) {
            return $cacheEntry->value;
        }

        return $this->probeHealthWithRetries($this->healthEndpointUrl(), 1)
            ->then(fn (array $health): array => $this->cacheAndRecordHealthResult($serverName, $cacheKey, $health));
    }

    /**
     * Probe the health endpoint, retrying transient transport failures up to the configured budget.
     *
     * Each resolved result carries its 1-based attempt number so diagnostics report retries.
     */
    /** @return PromiseInterface */
    private function probeHealthWithRetries(string $healthEndpointUrl, int $attempt): PromiseInterface
    {
        return $this->probeClient->probeNodeHealthAsync($healthEndpointUrl, $this->probePolicy->timeoutSeconds)->then(
            function (array $health) use ($healthEndpointUrl, $attempt): array|PromiseInterface {
                $health['attempts'] = $attempt;
                if ($attempt > $this->probePolicy->maxRetries || !$this->shouldRetry($health)) {
                    return $health;
                }

                $this->waitForRetry($this->probePolicy->backoffMicros($attempt));

                return $this->probeHealthWithRetries($healthEndpointUrl, $attempt + 1);
            }
        );
    }

    private function waitForRetry(int $micros): void
    {
        if ($this->retryDelay !== null) {
            ($this->retryDelay)($micros);

            return;
        }
        if ($micros > 0) {
            usleep($micros);
        }
    }

    /** @param HealthProbeResult $result */
    private function shouldRetry(array $result): bool
    {
        if (($result['status'] ?? null) !== NodeHealthState::UNREACHABLE) {
            return false;
        }

        return $this->probePolicy->isRetryable($result['failure_kind'] ?? null);
    }

    /**
     * @param HealthProbeResult $result
     *
     * @return HealthProbeResult
     */
    private function cacheAndRecordHealthResult(string $serverName, string $cacheKey, array $result): array
    {
        $result['state'] = NodeHealthStateResolver::resolve($result);
        $this->cacheStore->put($cacheKey, $result);
        $this->recordHealthEvent($serverName, $result);

        return $result;
    }

    /** @param HealthProbeResult $result */
    private function recordHealthEvent(string $serverName, array $result): void
    {
        $status = $result['status'] ?? NodeHealthState::UNREACHABLE;
        if ($status === NodeHealthState::HEALTHY) {
            return;
        }

        $isUnreachable = $status === NodeHealthState::UNREACHABLE;
        $eventType = $isUnreachable ? 'node_unreachable' : 'node_unhealthy';
        $message = $isUnreachable
            ? "No health response from {$serverName}"
            : "Unhealthy health response from {$serverName}";
        $diagnostics = $this->healthEventDiagnostics($result, $isUnreachable);

        $this->logger->warning("Node health -> {$message}", $diagnostics);
        $this->eventManager->handle(new EventCommand(
            type: $eventType,
            message: $message,
            level: $isUnreachable ? 'critical' : 'warning',
            server: $serverName,
            metric: new EventMetric('node_health', $isUnreachable ? 0 : 1, additional: $diagnostics),
        ));
    }

    /**
     * @param HealthProbeResult $result
     *
     * @return array<string,mixed>
     */
    private function healthEventDiagnostics(array $result, bool $isUnreachable): array
    {
        return [
            'failure_kind' => $result['failure_kind'] ?? ($isUnreachable ? 'unknown' : 'invalid_response'),
            'http_code' => $result['httpCode'] ?? null,
            'curl_errno' => $result['curl_errno'] ?? null,
            'timeout_ms' => $result['timeout_ms'] ?? $this->probePolicy->timeoutSeconds * 1000,
            'attempts' => $result['attempts'] ?? 1,
            'error' => $result['error'] ?? null,
            'checked_at' => isset($result['checked_at'])
                ? gmdate(DATE_ATOM, (int) $result['checked_at'])
                : gmdate(DATE_ATOM),
        ];
    }

    private function healthCacheKey(): string
    {
        return $this->cacheKeyFactory->createApiRequestKey($this->apiClient->getApiBase(), 'GET', 'health');
    }

    private function healthEndpointUrl(): string
    {
        return rtrim($this->apiClient->getApiBase(), '/') . '/health';
    }
}
