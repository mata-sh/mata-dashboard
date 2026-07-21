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
use Mata\Services\Monitor\Dto\ProbePolicy;
use Psr\Log\LoggerInterface;

/** Monitors the mata-node health endpoint with transient transport retry support. */
class NodeHealthMonitor implements NodeHealthMonitorInterface
{
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
        private ?Closure $retryDelay = null,
    ) {
        $this->probePolicy = $probePolicy ?? ProbePolicy::default();
    }

    public function checkOrWait(string $serverName, string $cacheType = CacheTypes::HEALTH, bool $forceRefresh = false): array
    {
        $result = $this->checkAsync($serverName, $cacheType, $forceRefresh);

        return is_array($result) ? $result : $result->wait();
    }

    public function checkAsync(string $serverName, string $cacheType = CacheTypes::HEALTH, bool $forceRefresh = false): array|PromiseInterface
    {
        $cacheKey = $this->cacheKeyFactory->createApiRequestKey($this->apiClient->getApiBase(), 'GET', 'health');
        $cached = $forceRefresh ? null : $this->cacheStore->get($cacheKey, $this->ttlResolver->get($cacheType));
        if ($cached !== null) {
            return $cached->value;
        }
        $healthUrl = rtrim($this->apiClient->getApiBase(), '/') . '/health';

        return $this->attemptHealth($healthUrl, 1)
            ->then(fn (array $health): array => $this->completeCheck($serverName, $cacheKey, $health));
    }

    /**
     * Probe the health endpoint, retrying transient transport failures up to the configured budget.
     *
     * Each resolved result carries its 1-based attempt number so diagnostics report retries.
     */
    private function attemptHealth(string $healthUrl, int $attempt): PromiseInterface
    {
        return $this->probeClient->checkHealthAsync($healthUrl, $this->probePolicy->timeoutSeconds)->then(
            function (array $health) use ($healthUrl, $attempt): array|PromiseInterface {
                $health['attempts'] = $attempt;
                if ($attempt > $this->probePolicy->maxRetries || !$this->shouldRetry($health)) {
                    return $health;
                }
                $this->waitForRetry($this->probePolicy->backoffMicros($attempt));

                return $this->attemptHealth($healthUrl, $attempt + 1);
            }
        );
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

    /** @param array<string,mixed> $result */
    private function shouldRetry(array $result): bool
    {
        return ($result['status'] ?? null) === 'unreachable'
            && $this->probePolicy->isRetryable($result['failure_kind'] ?? null);
    }

    /** @param array<string,mixed> $result
     * @return array<string,mixed> */
    private function completeCheck(string $serverName, string $cacheKey, array $result): array
    {
        $result['state'] = NodeHealthStateResolver::resolve($result);
        $this->cacheStore->put($cacheKey, $result);
        $this->recordHealthEvent($serverName, $result);

        return $result;
    }

    /** @param array<string,mixed> $result */
    private function recordHealthEvent(string $serverName, array $result): void
    {
        $status = $result['status'] ?? 'unreachable';
        if ($status === 'healthy') {
            return;
        }
        $isUnreachable = $status === 'unreachable';
        $type = $isUnreachable ? 'node_unreachable' : 'node_unhealthy';
        $message = $isUnreachable ? "No health response from {$serverName}" : "Unhealthy health response from {$serverName}";
        $diagnostics = [
            'failure_kind' => $result['failure_kind'] ?? ($isUnreachable ? 'unknown' : 'invalid_response'),
            'http_code' => $result['httpCode'] ?? null, 'curl_errno' => $result['curl_errno'] ?? null,
            'timeout_ms' => $result['timeout_ms'] ?? $this->probePolicy->timeoutSeconds * 1000,
            'attempts' => $result['attempts'] ?? 1, 'error' => $result['error'] ?? null,
            'checked_at' => isset($result['checked_at']) ? gmdate(DATE_ATOM, (int) $result['checked_at']) : gmdate(DATE_ATOM),
        ];
        $this->logger->warning("Node health -> {$message}", $diagnostics);
        $this->eventManager->handle(new EventCommand(
            type: $type,
            message: $message,
            level: $isUnreachable ? 'critical' : 'warning',
            server: $serverName,
            metric: new EventMetric('node_health', $isUnreachable ? 0 : 1, additional: $diagnostics),
        ));
    }
}
