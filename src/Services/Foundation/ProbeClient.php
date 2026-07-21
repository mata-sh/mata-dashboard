<?php

declare(strict_types=1);

namespace Mata\Services\Foundation;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\Utils;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

/** Unauthenticated concurrent HTTP probes with normalized failure diagnostics. */
class ProbeClient
{
    private const MAX_JSON_RESPONSE_BYTES = 65536;
    private const DNS_CURL_ERRNOS = [6];
    private const TLS_CURL_ERRNOS = [35, 51, 53, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90];
    private const CONNECT_CURL_ERRNOS = [5, 7, 45, 52, 55, 56];

    private ClientInterface $client;

    public function __construct(bool $verifySsl = true, ?ClientInterface $client = null)
    {
        $this->client = $client ?? new Client([
            'verify' => $verifySsl,
            'headers' => ['Accept' => 'application/json'],
        ]);
    }

    /** Probe whether a URL responds with a 2xx or 3xx status. */
    public function probeReachabilityAsync(string $url, int $timeout): PromiseInterface
    {
        $startedAt = microtime(true);

        return $this->requestReachabilityAsync($url, $timeout, $startedAt, 'HEAD');
    }

    /**
     * HEAD is the normal low-cost availability probe. Some servers reject it
     * despite serving GET, so retry a 405 once with GET.
     */
    private function requestReachabilityAsync(string $url, int $timeout, float $startedAt, string $method): PromiseInterface
    {
        return $this->client->requestAsync($method, $url, [
            'timeout' => $timeout,
            'allow_redirects' => true,
            'http_errors' => false,
        ])->then(
            function (ResponseInterface $response) use ($url, $timeout, $startedAt, $method): array|PromiseInterface {
                if ($method === 'HEAD' && $response->getStatusCode() === 405) {
                    return $this->requestReachabilityAsync($url, $timeout, $startedAt, 'GET');
                }

                return $this->reachabilityFromResponse($url, $startedAt, $response, $timeout);
            },
            fn (mixed $reason): array => $this->transportFailureResult($url, $reason, $timeout),
        );
    }

    /** Probe a JSON status endpoint and return its maintenance state. */
    public function probeStatusEndpointAsync(string $url, int $timeout): PromiseInterface
    {
        $startedAt = microtime(true);

        return $this->client->requestAsync('GET', $url, [
            'timeout' => $timeout,
            'allow_redirects' => false,
            'http_errors' => false,
            'headers' => ['Accept' => 'application/json'],
        ])->then(
            fn (ResponseInterface $response): array => $this->statusFromResponse($url, $startedAt, $response, $timeout),
            fn (mixed $reason): array => $this->transportFailureResult($url, $reason, $timeout),
        );
    }

    /** Probe a node health endpoint and normalize its health payload. */
    public function probeNodeHealthAsync(string $url, int $timeout): PromiseInterface
    {
        $startedAt = microtime(true);

        return $this->client->requestAsync('GET', $url, [
            'timeout' => $timeout,
            'allow_redirects' => true,
            'http_errors' => false,
            'headers' => ['Accept' => 'application/json'],
        ])->then(
            fn (ResponseInterface $response): array => $this->healthFromResponse($startedAt, $response, $timeout),
            fn (mixed $reason): array => $this->healthFromFailure($reason, $timeout),
        );
    }

    /**
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

    /** @return array<string,mixed> */
    private function reachabilityFromResponse(string $url, float $startedAt, ResponseInterface $response, int $timeout): array
    {
        $httpCode = $response->getStatusCode();
        $reachable = $httpCode >= 200 && $httpCode < 400;

        return $this->endpointProbeResult(
            url: $url,
            reachable: $reachable,
            httpCode: $httpCode,
            error: $reachable ? null : "HTTP {$httpCode}",
            failureKind: $reachable ? null : 'http',
            timeout: $timeout,
            latencyMs: $this->elapsedMilliseconds($startedAt),
        );
    }

    /** @return array<string,mixed> */
    private function transportFailureResult(string $url, mixed $reason, int $timeout): array
    {
        $exception = $this->asThrowable($reason);
        $errno = $this->curlErrno($exception);

        return $this->endpointProbeResult(
            url: $url,
            reachable: false,
            httpCode: $this->httpCodeFromFailure($exception),
            error: $exception->getMessage(),
            failureKind: $this->classifyFailure($errno, $exception->getMessage()),
            errno: $errno,
            timeout: $timeout,
        );
    }

    /** @return array<string,mixed> */
    private function endpointProbeResult(
        string $url,
        bool $reachable,
        ?int $httpCode,
        ?string $error,
        ?string $failureKind,
        int $timeout,
        ?int $errno = null,
        ?int $latencyMs = null,
    ): array {
        return [
            'reachable' => $reachable,
            'httpCode' => $httpCode,
            'latency_ms' => $latencyMs,
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
        $latencyMs = $this->elapsedMilliseconds($startedAt);

        try {
            if ($httpCode >= 300 && $httpCode < 400) {
                throw new RuntimeException('Status endpoint redirected');
            }
            $payload = json_decode($this->readJsonResponseBody($response, 'Status endpoint response'), false, 512, JSON_THROW_ON_ERROR);
            if (!is_object($payload)) {
                throw new RuntimeException('Invalid status endpoint response');
            }
            $payload = get_object_vars($payload);
            if (array_key_exists('maintenance_mode', $payload) && !is_bool($payload['maintenance_mode'])) {
                throw new RuntimeException('Invalid status endpoint response');
            }

            $reachable = $httpCode >= 200 && $httpCode < 400;
            $result = $this->endpointProbeResult(
                url: $url,
                reachable: $reachable,
                httpCode: $httpCode,
                error: $reachable ? null : "HTTP {$httpCode}",
                failureKind: $reachable ? null : 'http',
                timeout: $timeout,
                latencyMs: $latencyMs,
            );
            $result['maintenance_mode'] = $payload['maintenance_mode'] ?? false;

            return $result;
        } catch (Throwable) {
            return $this->endpointProbeResult(
                url: $url,
                reachable: false,
                httpCode: $httpCode,
                error: 'Invalid status endpoint response',
                failureKind: 'invalid_response',
                timeout: $timeout,
                latencyMs: $latencyMs,
            );
        }
    }

    private function readJsonResponseBody(ResponseInterface $response, string $label): string
    {
        $error = "{$label} is too large";
        $contentLength = $response->getHeaderLine('Content-Length');
        if ($contentLength !== '' && ctype_digit($contentLength) && (int) $contentLength > self::MAX_JSON_RESPONSE_BYTES) {
            throw new RuntimeException($error);
        }

        $body = $response->getBody();
        if (($body->getSize() ?? 0) > self::MAX_JSON_RESPONSE_BYTES) {
            throw new RuntimeException($error);
        }

        $content = '';
        while (!$body->eof()) {
            $content .= $body->read(min(8192, self::MAX_JSON_RESPONSE_BYTES + 1 - strlen($content)));
            if (strlen($content) > self::MAX_JSON_RESPONSE_BYTES) {
                throw new RuntimeException($error);
            }
        }

        return $content;
    }

    /** @return array<string,mixed> */
    private function healthFromResponse(float $startedAt, ResponseInterface $response, int $timeout): array
    {
        $httpCode = $response->getStatusCode();
        $latencyMs = $this->elapsedMilliseconds($startedAt);

        try {
            $payload = json_decode($this->readJsonResponseBody($response, 'Health response'), true, 512, JSON_THROW_ON_ERROR);
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
                'latency_ms' => $latencyMs,
                'error' => $isHealthy ? null : "HTTP {$httpCode} health status is not healthy",
                'failure_kind' => $isHealthy ? null : ($httpCode >= 400 ? 'http' : 'invalid_response'),
                'curl_errno' => null,
                'timeout_ms' => $timeout * 1000,
                'attempts' => 1,
                'checked_at' => time(),
            ];
        } catch (Throwable $exception) {
            return $this->unreachableHealth($exception, $httpCode, $timeout, 'invalid_response', latencyMs: $latencyMs);
        }
    }

    /** @return array<string,mixed> */
    private function healthFromFailure(mixed $reason, int $timeout): array
    {
        $exception = $this->asThrowable($reason);
        $errno = $this->curlErrno($exception);

        return $this->unreachableHealth(
            exception: $exception,
            httpCode: $this->httpCodeFromFailure($exception),
            timeout: $timeout,
            kind: $this->classifyFailure($errno, $exception->getMessage()),
            errno: $errno,
        );
    }

    /** @return array<string,mixed> */
    private function unreachableHealth(
        Throwable $exception,
        ?int $httpCode,
        int $timeout,
        string $kind,
        ?int $errno = null,
        ?int $latencyMs = null,
    ): array {
        return [
            'status' => 'unreachable',
            'httpCode' => $httpCode,
            'maintenance_mode' => null,
            'checks' => null,
            'node_timestamp' => null,
            'latency_ms' => $latencyMs,
            'error' => $exception->getMessage(),
            'failure_kind' => $kind,
            'curl_errno' => $errno,
            'timeout_ms' => $timeout * 1000,
            'attempts' => 1,
            'checked_at' => time(),
        ];
    }

    private function elapsedMilliseconds(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
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

    private function curlErrno(Throwable $exception): ?int
    {
        if (!$exception instanceof RequestException && !$exception instanceof ConnectException) {
            return null;
        }

        $errno = $exception->getHandlerContext()['errno'] ?? null;

        return is_numeric($errno) ? (int) $errno : null;
    }

    private function classifyFailure(?int $errno, string $message): string
    {
        $message = strtolower($message);
        if ($this->matchesFailure($errno, $message, self::DNS_CURL_ERRNOS, [
            'could not resolve',
            'resolving timed out',
            'name or service not known',
        ])) {
            return 'dns';
        }
        if ($this->matchesFailure($errno, $message, self::TLS_CURL_ERRNOS, ['ssl', 'tls', 'certificate'])) {
            return 'tls';
        }
        if ($this->matchesFailure($errno, $message, self::CONNECT_CURL_ERRNOS, [
            'failed to connect',
            'connection timed out',
        ])) {
            return 'connect';
        }
        if ($errno === 28 || $this->containsAny($message, ['timed out', 'timeout'])) {
            return 'timeout';
        }

        return 'unknown';
    }

    /** @param list<int> $errnos
     * @param list<string> $fragments */
    private function matchesFailure(?int $errno, string $message, array $errnos, array $fragments): bool
    {
        return in_array($errno, $errnos, true) || $this->containsAny($message, $fragments);
    }

    /** @param list<string> $fragments */
    private function containsAny(string $message, array $fragments): bool
    {
        foreach ($fragments as $fragment) {
            if (str_contains($message, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
