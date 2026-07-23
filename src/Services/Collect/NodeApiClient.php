<?php

declare(strict_types=1);

namespace Mata\Services\Collect;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
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
class NodeApiClient
{
    private Client $client;
    private LoggerInterface $logger;
    private string $apiBase;
    private string $server;
    private string $apiKey;
    private HmacAuthTokenGenerator $tokenGenerator;

    /**
     * Initialize HTTP client with authentication.
     *
     * @param LoggerInterface   $logger              PSR-3 logger for HTTP request logging
     * @param string            $apiBase             Base URL for the API endpoint
     * @param string            $server              Server identifier included in HMAC tokens
     * @param string            $apiKey              Shared secret used to sign each request
     * @param int               $timeout             Request timeout in seconds (default: 10)
     * @param bool              $verifySSL           Whether to verify SSL certificates (default: true)
     * @param bool              $allowHttp           Whether to allow HTTP connections (default: false)
     * @param bool              $suppressHttpWarning Whether to suppress HTTP security warnings (default: false)
     * @param null|HandlerStack $handlerStack        Optional Guzzle handler stack for testing
     */
    public function __construct(
        LoggerInterface $logger,
        string $apiBase,
        string $server,
        string $apiKey,
        int $timeout = 10,
        bool $verifySSL = true,
        bool $allowHttp = false,
        bool $suppressHttpWarning = false,
        ?HandlerStack $handlerStack = null
    ) {
        $this->logger = $logger;
        $this->apiBase = $apiBase;
        $this->server = $server;
        $this->apiKey = $apiKey;
        $this->tokenGenerator = new HmacAuthTokenGenerator();

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

        $handlerStack ??= HandlerStack::create();
        $handlerStack->push(Middleware::mapRequest(
            fn (RequestInterface $request): RequestInterface => $request->withHeader(
                'X-Mata-Auth',
                $this->tokenGenerator->generate($this->server, $this->apiKey)
            )
        ), 'mata_auth');

        $this->client = new Client([
            'base_uri' => rtrim($apiBase, '/') . '/',
            'timeout' => $timeout,
            'verify' => $verifySSL,
            'handler' => $handlerStack,
            'headers' => [
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
        $body = $this->requestAsync($method, $path, $options)->wait();
        if (!is_string($body)) {
            throw new RuntimeException('Invalid API response body');
        }

        return $body;
    }

    /**
     * Start an HTTP request with a token generated specifically for this request.
     *
     * @param string              $method  HTTP method
     * @param string              $path    API endpoint path
     * @param array<string,mixed> $options Additional Guzzle request options
     */
    public function requestAsync(string $method, string $path, array $options = []): PromiseInterface
    {
        $this->logger->debug("API Request: {$method} {$path}", [
            'api_base' => $this->apiBase,
            'path' => $path,
        ]);

        return $this->client->requestAsync($method, $path, $options)->then(
            function (ResponseInterface $response): string {
                $body = (string) $response->getBody();

                $this->logger->debug('API Response received', [
                    'status_code' => $response->getStatusCode(),
                    'content_length' => strlen($body),
                ]);

                return $body;
            },
            function (mixed $reason) use ($method, $path): never {
                $exception = $reason instanceof Throwable
                    ? $reason
                    : new RuntimeException((string) $reason);

                $this->logger->error("API Request failed: {$method} {$path}", [
                    'error' => $exception->getMessage(),
                    'code' => $exception->getCode(),
                    'api_base' => $this->apiBase,
                ]);

                throw $exception;
            }
        );
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
}
