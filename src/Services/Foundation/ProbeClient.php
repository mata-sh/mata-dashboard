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

/** Unauthenticated concurrent HTTP probes with normalized failure diagnostics. */
class ProbeClient
{
    private const MAX_STATUS_RESPONSE_BYTES = 65536;

    private ClientInterface $client;

    public function __construct(bool $verifySsl = true, ?ClientInterface $client = null)
    {
        $this->client = $client ?? new Client(['verify' => $verifySsl, 'headers' => ['Accept' => 'application/json']]);
    }

    /** HEAD probe: treats any 2xx/3xx response as reachable. */
    public function checkAvailabilityAsync(string $url, int $timeout): PromiseInterface
    {
        $startedAt = microtime(true);

        return $this->client->requestAsync('HEAD', $url, ['timeout' => $timeout, 'allow_redirects' => true, 'http_errors' => false])->then(
            fn (ResponseInterface $response): array => $this->availabilityFromResponse($url, $startedAt, $response, $timeout),
            fn (mixed $reason): array => $this->availabilityFromFailure($url, $reason, $timeout),
        );
    }

    /** GET status probe: requires a valid JSON payload and reads maintenance mode. */
    public function checkStatusAsync(string $url, int $timeout): PromiseInterface
    {
        $startedAt = microtime(true);

        return $this->client->requestAsync('GET', $url, [
            'timeout' => $timeout,
            'http_errors' => false,
            'allow_redirects' => false,
            'headers' => ['Accept' => 'application/json'],
        ])->then(
            fn (ResponseInterface $response): array => $this->statusFromResponse($url, $startedAt, $response, $timeout),
            fn (mixed $reason): array => $this->statusFromFailure($url, $reason, $timeout),
        );
    }

    /** GET health probe: normalizes node health, checks, and maintenance state. */
    public function checkHealthAsync(string $url, int $timeout): PromiseInterface
    {
        $startedAt = microtime(true);

        return $this->client->requestAsync('GET', $url, ['timeout' => $timeout, 'http_errors' => false])->then(
            fn (ResponseInterface $response): array => $this->healthFromResponse($startedAt, $response, $timeout),
            fn (mixed $reason): array => $this->healthFromFailure($reason, $timeout),
        );
    }

    /** @param array<int|string,PromiseInterface> $promises
     * @return array<int|string,array<string,mixed>> */
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

    /** @return array<string,mixed> */
    private function availabilityFromResponse(string $url, float $startedAt, ResponseInterface $response, int $timeout): array
    {
        $httpCode = $response->getStatusCode();
        $reachable = $httpCode >= 200 && $httpCode < 400;

        return $this->availabilityResult($url, $reachable, $httpCode, $reachable ? null : "HTTP {$httpCode}", $reachable ? null : 'http', null, $timeout, $startedAt);
    }

    /** @return array<string,mixed> */
    private function availabilityFromFailure(string $url, mixed $reason, int $timeout): array
    {
        $exception = $this->asThrowable($reason);
        [$kind, $errno] = $this->failureDetails($exception);

        return $this->availabilityResult($url, false, $this->httpCodeFromFailure($exception), $exception->getMessage(), $kind, $errno, $timeout);
    }

    /** @return array<string,mixed> */
    private function availabilityResult(string $url, bool $reachable, ?int $httpCode, ?string $error, ?string $failureKind, ?int $errno, int $timeout, ?float $startedAt = null): array
    {
        return [
            'reachable' => $reachable,
            'httpCode' => $httpCode,
            'latency_ms' => $reachable && $startedAt !== null ? (int) round((microtime(true) - $startedAt) * 1000) : null,
            'error' => $error,
            'failure_kind' => $failureKind,
            'curl_errno' => $errno,
            'timeout_ms' => $timeout * 1000,
            'attempts' => 1,
            'url' => $url,
            'maintenance_mode' => false,
            'checked_at' => time(),
        ];
    }

    /** @return array<string,mixed> */
    private function statusFromResponse(string $url, float $startedAt, ResponseInterface $response, int $timeout): array
    {
        $httpCode = $response->getStatusCode();

        try {
            if ($httpCode >= 300 && $httpCode < 400) {
                throw new RuntimeException('Status endpoint redirected');
            }
            $payload = json_decode($this->readStatusResponseBody($response), false, 512, JSON_THROW_ON_ERROR);
            if (!is_object($payload)) {
                throw new RuntimeException('Invalid status endpoint response');
            }
            $payload = get_object_vars($payload);
            if (array_key_exists('maintenance_mode', $payload) && !is_bool($payload['maintenance_mode'])) {
                throw new RuntimeException('Invalid status endpoint response');
            }

            $reachable = $httpCode >= 200 && $httpCode < 400;
            $result = $this->availabilityResult($url, $reachable, $httpCode, $reachable ? null : "HTTP {$httpCode}", $reachable ? null : 'http', null, $timeout, $startedAt);
            $result['maintenance_mode'] = $payload['maintenance_mode'] ?? false;

            return $result;
        } catch (Throwable $exception) {
            return $this->availabilityResult($url, false, $httpCode, 'Invalid status endpoint response', 'invalid_response', null, $timeout);
        }
    }

    /** @return array<string,mixed> */
    private function statusFromFailure(string $url, mixed $reason, int $timeout): array
    {
        return $this->availabilityFromFailure($url, $reason, $timeout);
    }

    private function readStatusResponseBody(ResponseInterface $response): string
    {
        $contentLength = $response->getHeaderLine('Content-Length');
        if ($contentLength !== '' && ctype_digit($contentLength) && (int) $contentLength > self::MAX_STATUS_RESPONSE_BYTES) {
            throw new RuntimeException('Status endpoint response is too large');
        }
        $body = $response->getBody();
        if (($body->getSize() ?? 0) > self::MAX_STATUS_RESPONSE_BYTES) {
            throw new RuntimeException('Status endpoint response is too large');
        }
        $content = '';
        while (!$body->eof()) {
            $content .= $body->read(min(8192, self::MAX_STATUS_RESPONSE_BYTES + 1 - strlen($content)));
            if (strlen($content) > self::MAX_STATUS_RESPONSE_BYTES) {
                throw new RuntimeException('Status endpoint response is too large');
            }
        }

        return $content;
    }

    /** @return array<string,mixed> */
    private function healthFromResponse(float $startedAt, ResponseInterface $response, int $timeout): array
    {
        $httpCode = $response->getStatusCode();

        try {
            $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($payload)) {
                throw new RuntimeException('Invalid health response payload');
            }
            $isHealthy = $httpCode >= 200 && $httpCode < 300 && ($payload['status'] ?? null) === 'healthy';

            return [
                'status' => $isHealthy ? 'healthy' : 'unhealthy', 'httpCode' => $httpCode,
                'maintenance_mode' => is_bool($payload['maintenance_mode'] ?? null) ? $payload['maintenance_mode'] : null,
                'checks' => is_array($payload['checks'] ?? null) ? $payload['checks'] : null,
                'node_timestamp' => $payload['timestamp'] ?? null,
                'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'error' => $isHealthy ? null : "HTTP {$httpCode} health status is not healthy",
                'failure_kind' => $isHealthy ? null : ($httpCode >= 400 ? 'http' : 'invalid_response'),
                'curl_errno' => null, 'timeout_ms' => $timeout * 1000, 'attempts' => 1, 'checked_at' => time(),
            ];
        } catch (Throwable $exception) {
            return $this->unreachableHealth($exception, $httpCode, $timeout, 'invalid_response');
        }
    }

    /** @return array<string,mixed> */
    private function healthFromFailure(mixed $reason, int $timeout): array
    {
        $exception = $this->asThrowable($reason);
        [$kind, $errno] = $this->failureDetails($exception);

        return $this->unreachableHealth($exception, $this->httpCodeFromFailure($exception), $timeout, $kind, $errno);
    }

    /** @return array<string,mixed> */
    private function unreachableHealth(Throwable $exception, ?int $httpCode, int $timeout, string $kind, ?int $errno = null): array
    {
        return [
            'status' => 'unreachable', 'httpCode' => $httpCode, 'maintenance_mode' => null, 'checks' => null,
            'node_timestamp' => null, 'latency_ms' => null, 'error' => $exception->getMessage(),
            'failure_kind' => $kind, 'curl_errno' => $errno, 'timeout_ms' => $timeout * 1000, 'attempts' => 1,
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
        $responseCode = $exception->getResponse()?->getStatusCode();
        if ($responseCode !== null) {
            return $responseCode;
        }
        $code = $exception->getCode();

        return is_int($code) && $code !== 0 ? $code : null;
    }

    /** @return array{string,?int} */
    private function failureDetails(Throwable $exception): array
    {
        $context = method_exists($exception, 'getHandlerContext') ? $exception->getHandlerContext() : [];
        $errno = is_array($context) && is_numeric($context['errno'] ?? null) ? (int) $context['errno'] : null;
        $message = strtolower($exception->getMessage());
        $kind = match (true) {
            in_array($errno, [6], true) || str_contains($message, 'could not resolve') || str_contains($message, 'resolving timed out') || str_contains($message, 'name or service not known') => 'dns',
            in_array($errno, [35, 51, 53, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90], true) || str_contains($message, 'ssl') || str_contains($message, 'tls') || str_contains($message, 'certificate') => 'tls',
            in_array($errno, [5, 7, 45, 52, 55, 56], true) || str_contains($message, 'failed to connect') || str_contains($message, 'connection timed out') => 'connect',
            $errno === 28 || str_contains($message, 'timed out') || str_contains($message, 'timeout') => 'timeout',
            default => 'unknown',
        };

        return [$kind, $errno];
    }
}
