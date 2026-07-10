<?php

declare(strict_types=1);

namespace Mata\Services\Foundation;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * HTTP API communication client.
 *
 * Handles low-level HTTP communication:
 * - Guzzle HTTP client configuration
 * - Request execution with authentication
 * - Response retrieval and basic validation
 * - Error handling and logging
 */
class ApiClient
{
    private Client $client;
    private LoggerInterface $logger;
    private string $apiBase;
    private string $token;

    /**
     * Initialize HTTP client with authentication.
     *
     * @param LoggerInterface $logger              PSR-3 logger for HTTP request logging
     * @param string          $apiBase             Base URL for the API endpoint
     * @param string          $token               Authentication token for API requests (HMAC)
     * @param int             $timeout             Request timeout in seconds (default: 10)
     * @param bool            $verifySSL           Whether to verify SSL certificates (default: true)
     * @param bool            $allowHttp           Whether to allow HTTP connections (default: false)
     * @param bool            $suppressHttpWarning Whether to suppress HTTP security warnings (default: false)
     */
    public function __construct(
        LoggerInterface $logger,
        string $apiBase,
        string $token,
        int $timeout = 10,
        bool $verifySSL = true,
        bool $allowHttp = false,
        bool $suppressHttpWarning = false
    ) {
        $this->logger = $logger;
        $this->apiBase = $apiBase;
        $this->token = $token;

        // Enforce HTTPS protocol (unless explicitly allowed)
        $parsedUrl = parse_url($apiBase);
        $scheme = $parsedUrl['scheme'] ?? null;
        $isHttps = ($scheme === 'https');

        if (!$isHttps) {
            if (!$allowHttp) {
                throw new RuntimeException(
                    "API base URL must use HTTPS protocol (found: {$scheme}). "
                    . 'Insecure HTTP connections are not allowed. '
                    . "To allow HTTP for development/testing, enable the 'allow_http' configuration flag."
                );
            }

            // HTTP allowed but log security warning unless suppressed
            if (!$suppressHttpWarning) {
                $this->logger->warning('Security warning: HTTP connection established (insecure)', [
                    'api_base' => $apiBase,
                    'scheme' => $scheme,
                    'reason' => 'allow_http flag is enabled',
                    'recommendation' => 'Use HTTPS for production environments to protect API keys and data',
                ]);
            }
        }

        $this->client = new Client([
            'base_uri' => rtrim($apiBase, '/') . '/',
            'timeout' => $timeout,
            'verify' => $verifySSL,
            'headers' => [
                'X-Mata-Auth' => $this->token,
                'Accept' => 'application/json',
            ],
        ]);
    }

    /**
     * Execute HTTP request.
     *
     * @param string              $method  HTTP method (GET, POST, PUT, DELETE)
     * @param string              $path    API endpoint path
     * @param array<string,mixed> $options Additional Guzzle request options
     *
     * @return string Raw response body
     *
     * @throws RequestException When HTTP request fails
     * @throws RuntimeException When response is invalid
     */
    public function request(string $method, string $path, array $options = []): string
    {
        $this->logger->debug("API Request: {$method} {$path}", [
            'api_base' => $this->apiBase,
            'path' => $path,
        ]);

        try {
            $response = $this->client->request($method, $path, $options);
            $body = (string) $response->getBody();

            $this->logger->debug('API Response received', [
                'status_code' => $response->getStatusCode(),
                'content_length' => strlen($body),
            ]);

            return $body;
        } catch (RequestException $e) {
            $this->logger->error("API Request failed: {$method} {$path}", [
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
                'api_base' => $this->apiBase,
            ]);

            throw $e;
        }
    }

    /**
     * Execute GET request.
     *
     * @param string              $path    API endpoint path
     * @param array<string,mixed> $options Additional Guzzle request options
     *
     * @return string Raw response body
     *
     * @throws RequestException When HTTP request fails
     */
    public function get(string $path, array $options = []): string
    {
        return $this->request('GET', $path, $options);
    }

    /**
     * Execute POST request.
     *
     * @param string              $path    API endpoint path
     * @param array<string,mixed> $data    Request data
     * @param array<string,mixed> $options Additional Guzzle request options
     *
     * @return string Raw response body
     *
     * @throws RequestException When HTTP request fails
     */
    public function post(string $path, array $data = [], array $options = []): string
    {
        $options['json'] = $data;

        return $this->request('POST', $path, $options);
    }

    /**
     * Execute PUT request.
     *
     * @param string              $path    API endpoint path
     * @param array<string,mixed> $data    Request data
     * @param array<string,mixed> $options Additional Guzzle request options
     *
     * @return string Raw response body
     *
     * @throws RequestException When HTTP request fails
     */
    public function put(string $path, array $data = [], array $options = []): string
    {
        $options['json'] = $data;

        return $this->request('PUT', $path, $options);
    }

    /**
     * Execute DELETE request.
     *
     * @param string              $path    API endpoint path
     * @param array<string,mixed> $options Additional Guzzle request options
     *
     * @return string Raw response body
     *
     * @throws RequestException When HTTP request fails
     */
    public function delete(string $path, array $options = []): string
    {
        return $this->request('DELETE', $path, $options);
    }

    /**
     * Get node health without treating an unhealthy (503) response as an exception.
     *
     * @return array<string,mixed>
     */
    public function getHealth(int $timeout = 3): array
    {
        $startTime = microtime(true);
        $httpCode = null;

        try {
            $response = $this->client->request('GET', 'health', [
                'timeout' => $timeout,
                'http_errors' => false,
            ]);
            $latencyMs = (int) round((microtime(true) - $startTime) * 1000);
            $httpCode = $response->getStatusCode();
            $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

            if (!is_array($payload)) {
                throw new RuntimeException('Invalid health response payload');
            }

            $reportedStatus = $payload['status'] ?? null;
            $isHealthy = $httpCode >= 200 && $httpCode < 300 && $reportedStatus === 'healthy';

            return [
                'status' => $isHealthy ? 'healthy' : 'unhealthy',
                'httpCode' => $httpCode,
                'maintenance_mode' => is_bool($payload['maintenance_mode'] ?? null) ? $payload['maintenance_mode'] : null,
                'checks' => is_array($payload['checks'] ?? null) ? $payload['checks'] : null,
                'node_timestamp' => $payload['timestamp'] ?? null,
                'latency_ms' => $latencyMs,
                'error' => null,
                'checked_at' => time(),
            ];
        } catch (Throwable $e) {
            return [
                'status' => 'unreachable',
                'httpCode' => $httpCode ?? ($e instanceof RequestException && $e->getCode() ? $e->getCode() : null),
                'maintenance_mode' => null,
                'checks' => null,
                'node_timestamp' => null,
                'latency_ms' => null,
                'error' => $e->getMessage(),
                'checked_at' => time(),
            ];
        }
    }

    /**
     * Get the base API URL.
     *
     * @return string Base API URL
     */
    public function getApiBase(): string
    {
        return $this->apiBase;
    }

    /**
     * Check if a URL is reachable via HTTP HEAD request.
     *
     * @param string $url     URL to check
     * @param int    $timeout Timeout in seconds (default: 5)
     *
     * @return bool True if reachable (HTTP 2xx or 3xx), false otherwise
     */
    public function isUrlReachable(string $url, int $timeout = 5): bool
    {
        try {
            $response = $this->client->request('HEAD', $url, [
                'timeout' => $timeout,
                'allow_redirects' => true,
                'headers' => [], // Don't use auth headers for external URLs
            ]);

            $httpCode = $response->getStatusCode();

            return $httpCode >= 200 && $httpCode < 400;
        } catch (RequestException $e) {
            $this->logger->debug("URL not reachable: {$url}", [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Check availability of a URL with performance metrics.
     *
     * @param string $url     URL to check
     * @param int    $timeout Timeout in seconds (default: 5)
     *
     * @return array<string,mixed> Availability data with reachable, httpCode, latency_ms, error
     */
    public function checkAvailability(string $url, int $timeout = 5): array
    {
        $startTime = microtime(true);

        try {
            $response = $this->client->request('HEAD', $url, [
                'timeout' => $timeout,
                'allow_redirects' => true,
                'headers' => [], // Don't use auth headers for external URLs
            ]);

            $endTime = microtime(true);
            $latencyMs = round(($endTime - $startTime) * 1000);
            $httpCode = $response->getStatusCode();
            $reachable = $httpCode >= 200 && $httpCode < 400;

            return [
                'reachable' => $reachable,
                'httpCode' => $httpCode,
                'latency_ms' => $reachable ? $latencyMs : null,
                'error' => null,
                'url' => $url,
                'checked_at' => time(),
            ];
        } catch (RequestException $e) {
            $endTime = microtime(true);
            $latencyMs = round(($endTime - $startTime) * 1000);

            return [
                'reachable' => false,
                'httpCode' => $e->getCode() ?: null,
                'latency_ms' => null,
                'error' => $e->getMessage(),
                'url' => $url,
                'checked_at' => time(),
            ];
        }
    }
}
