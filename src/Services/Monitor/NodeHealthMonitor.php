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
use Mata\Services\Monitor\Dto\NodeHealthResult;
use Mata\Services\Monitor\Dto\ProbePolicy;
use Psr\Log\LoggerInterface;
use UnexpectedValueException;

/** Monitors the mata-node health endpoint with transient transport retry support. */
class NodeHealthMonitor implements NodeHealthMonitorInterface
{
    private readonly ProbePolicy $probePolicy;

    /** @param null|Closure(int):void $retryDelay Retry-delay hook used by deterministic tests. */
    public function __construct(
        private CacheStoreInterface $cacheStore,
        private ApiCacheKeyFactory $cacheKeyFactory,
        private CacheTtlResolver $ttlResolver,
        private NodeApiClient $apiClient,
        private HttpProbeClient $probeClient,
        private EventManager $eventManager,
        private LoggerInterface $logger,
        ?ProbePolicy $probePolicy = null,
        private ?Closure $retryDelay = null,
    ) {
        $this->probePolicy = $probePolicy ?? ProbePolicy::default();
    }

    public function checkSynchronously(
        string $serverName,
        CacheType $cacheTtlType = CacheType::Health,
        bool $forceRefresh = false,
    ): NodeHealthResult {
        $result = $this->checkAsync($serverName, $cacheTtlType, $forceRefresh);
        if ($result instanceof NodeHealthResult) {
            return $result;
        }

        $resolved = $result->wait();
        if (!$resolved instanceof NodeHealthResult) {
            throw new UnexpectedValueException('Node health probe returned an invalid result.');
        }

        return $resolved;
    }

    public function checkAsync(
        string $serverName,
        CacheType $cacheTtlType = CacheType::Health,
        bool $forceRefresh = false,
    ): NodeHealthResult|PromiseInterface {
        $cacheKey = $this->healthCacheKey();
        $cacheEntry = $forceRefresh
            ? null
            : $this->cacheStore->get($cacheKey, $this->ttlResolver->get($cacheTtlType));
        if ($cacheEntry !== null) {
            return NodeHealthResult::fromArray($cacheEntry->value);
        }

        return $this->probeHealthWithRetries($this->healthEndpointUrl(), 1)
            ->then(fn (NodeHealthResult $health): NodeHealthResult => $this->cacheAndRecordHealthResult(
                $serverName,
                $cacheKey,
                $health,
            ));
    }

    /**
     * Probe the health endpoint, retrying transient transport failures up to the configured budget.
     *
     * Each resolved result carries its 1-based attempt number so diagnostics report retries.
     */
    private function probeHealthWithRetries(string $healthEndpointUrl, int $attempt): PromiseInterface
    {
        return $this->probeClient->probeNodeHealthAsync($healthEndpointUrl, $this->probePolicy->timeoutSeconds)->then(
            function (array $health) use ($healthEndpointUrl, $attempt): NodeHealthResult|PromiseInterface {
                $result = NodeHealthResult::fromArray($health);
                $result = $result->withAttempts($attempt);
                if ($attempt > $this->probePolicy->maxRetries || !$this->shouldRetry($result)) {
                    return $result;
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

    private function shouldRetry(NodeHealthResult $result): bool
    {
        if ($result->status !== NodeHealthState::Unreachable) {
            return false;
        }

        return $this->probePolicy->isRetryable($result->failureKind);
    }

    private function cacheAndRecordHealthResult(
        string $serverName,
        string $cacheKey,
        NodeHealthResult $result,
    ): NodeHealthResult {
        $this->cacheStore->put($cacheKey, $result->toArray());
        $this->recordHealthEvent($serverName, $result);

        return $result;
    }

    private function recordHealthEvent(string $serverName, NodeHealthResult $result): void
    {
        if ($result->status === NodeHealthState::Healthy) {
            return;
        }

        $isUnreachable = $result->status === NodeHealthState::Unreachable;
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

    /** @return array<string,mixed> */
    private function healthEventDiagnostics(NodeHealthResult $result, bool $isUnreachable): array
    {
        return [
            'failure_kind' => $result->failureKind ?? ($isUnreachable ? 'unknown' : 'invalid_response'),
            'http_code' => $result->httpCode,
            'curl_errno' => $result->curlErrno,
            'timeout_ms' => $result->timeoutMs,
            'attempts' => $result->attempts,
            'error' => $result->error,
            'checked_at' => gmdate(DATE_ATOM, $result->checkedAt),
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
