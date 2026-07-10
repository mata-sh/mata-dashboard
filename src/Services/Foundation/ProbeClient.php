<?php

declare(strict_types=1);

namespace Mata\Services\Foundation;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\Utils;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

/**
 * Unauthenticated concurrent HTTP probes.
 */
class ProbeClient
{
    private ClientInterface $client;

    public function __construct(bool $verifySsl = true, ?ClientInterface $client = null)
    {
        $this->client = $client ?? new Client([
            'verify' => $verifySsl,
            'headers' => ['Accept' => 'application/json'],
        ]);
    }

    public function checkAvailabilityAsync(string $url, int $timeout): PromiseInterface
    {
        $startedAt = microtime(true);

        return $this->client->requestAsync('HEAD', $url, [
            'timeout' => $timeout,
            'allow_redirects' => true,
        ])->then(
            fn (ResponseInterface $response): array => $this->availabilityFromResponse($url, $startedAt, $response),
            fn (mixed $reason): array => $this->availabilityFromFailure($url, $reason),
        );
    }

    public function getHealthAsync(string $url, int $timeout): PromiseInterface
    {
        $startedAt = microtime(true);

        return $this->client->requestAsync('GET', $url, [
            'timeout' => $timeout,
            'http_errors' => false,
        ])->then(
            fn (ResponseInterface $response): array => $this->healthFromResponse($startedAt, $response),
            fn (mixed $reason): array => $this->healthFromFailure($reason),
        );
    }

    /**
     * Wait for a batch once while preserving its keys.
     *
     * @param array<int|string,PromiseInterface> $promises
     *
     * @return array<int|string,array<string,mixed>>
     */
    public function settle(array $promises): array
    {
        if ($promises === []) {
            return [];
        }

        $settled = Utils::settle($promises)->wait();
        $results = [];
        foreach ($settled as $key => $outcome) {
            if ($outcome['state'] === 'fulfilled' && is_array($outcome['value'])) {
                $results[$key] = $outcome['value'];
            }
        }

        return $results;
    }

    /**
     * @return array<string,mixed>
     */
    private function availabilityFromResponse(string $url, float $startedAt, ResponseInterface $response): array
    {
        $httpCode = $response->getStatusCode();
        $reachable = $httpCode >= 200 && $httpCode < 400;

        return [
            'reachable' => $reachable,
            'httpCode' => $httpCode,
            'latency_ms' => $reachable ? (int) round((microtime(true) - $startedAt) * 1000) : null,
            'error' => null,
            'url' => $url,
            'checked_at' => time(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function availabilityFromFailure(string $url, mixed $reason): array
    {
        $exception = $this->asThrowable($reason);

        return [
            'reachable' => false,
            'httpCode' => $this->httpCodeFromFailure($exception),
            'latency_ms' => null,
            'error' => $exception->getMessage(),
            'url' => $url,
            'checked_at' => time(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function healthFromResponse(float $startedAt, ResponseInterface $response): array
    {
        $httpCode = $response->getStatusCode();

        try {
            $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($payload)) {
                throw new RuntimeException('Invalid health response payload');
            }

            $isHealthy = $httpCode >= 200 && $httpCode < 300 && ($payload['status'] ?? null) === 'healthy';

            return [
                'status' => $isHealthy ? 'healthy' : 'unhealthy',
                'httpCode' => $httpCode,
                'maintenance_mode' => is_bool($payload['maintenance_mode'] ?? null) ? $payload['maintenance_mode'] : null,
                'checks' => is_array($payload['checks'] ?? null) ? $payload['checks'] : null,
                'node_timestamp' => $payload['timestamp'] ?? null,
                'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'error' => null,
                'checked_at' => time(),
            ];
        } catch (Throwable $exception) {
            return $this->unreachableHealth($exception, $httpCode);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function healthFromFailure(mixed $reason): array
    {
        $exception = $this->asThrowable($reason);

        return $this->unreachableHealth($exception, $this->httpCodeFromFailure($exception));
    }

    /**
     * @return array<string,mixed>
     */
    private function unreachableHealth(Throwable $exception, ?int $httpCode): array
    {
        return [
            'status' => 'unreachable',
            'httpCode' => $httpCode,
            'maintenance_mode' => null,
            'checks' => null,
            'node_timestamp' => null,
            'latency_ms' => null,
            'error' => $exception->getMessage(),
            'checked_at' => time(),
        ];
    }

    private function asThrowable(mixed $reason): Throwable
    {
        return $reason instanceof Throwable ? $reason : new RuntimeException((string) $reason);
    }

    private function httpCodeFromFailure(Throwable $exception): ?int
    {
        if (!$exception instanceof RequestException) {
            return null;
        }

        $code = $exception->getCode();

        return is_int($code) && $code !== 0 ? $code : null;
    }
}
