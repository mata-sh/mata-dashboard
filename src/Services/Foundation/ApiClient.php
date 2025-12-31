<?php

declare(strict_types=1);

namespace Watcher\Services\Foundation;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;
use RuntimeException;

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
     * @param LoggerInterface $logger    PSR-3 logger for HTTP request logging
     * @param string          $apiBase   Base URL for the API endpoint
     * @param string          $token     Authentication token for API requests
     * @param int             $timeout   Request timeout in seconds (default: 10)
     * @param bool            $verifySSL Whether to verify SSL certificates (default: true)
     */
    public function __construct(
        LoggerInterface $logger,
        string $apiBase,
        string $token,
        int $timeout = 10,
        bool $verifySSL = true
    ) {
        $this->logger = $logger;
        $this->apiBase = $apiBase;
        $this->token = $token;

        $this->client = new Client([
            'base_uri' => rtrim($apiBase, '/') . '/',
            'timeout' => $timeout,
            'verify' => $verifySSL,
            'headers' => [
                'Auth' => "Bearer {$this->token}",
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
