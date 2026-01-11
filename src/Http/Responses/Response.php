<?php

declare(strict_types=1);

namespace Mata\Http\Responses;

/**
 * Base HTTP Response class.
 *
 * Core functionality all responses need:
 * - Status codes
 * - Headers management
 * - Content handling
 * - Sending response to client
 */
class Response
{
    protected int $statusCode;

    /** @var array<string,string> */
    protected array $headers;
    protected string $content;

    /**
     * @param array<string,string> $headers
     */
    public function __construct(string $content = '', int $statusCode = 200, array $headers = [])
    {
        $this->content = $content;
        $this->statusCode = $statusCode;
        $this->headers = $headers;
    }

    public function setStatusCode(int $statusCode): self
    {
        $this->statusCode = $statusCode;

        return $this;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function addHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;

        return $this;
    }

    /**
     * @return array<string,string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function setContent(string $content): self
    {
        $this->content = $content;

        return $this;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    /**
     * Send the response to the client.
     */
    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->statusCode);

            foreach ($this->headers as $name => $value) {
                header("{$name}: {$value}");
            }
        }

        echo $this->content;
    }

    /**
     * Check if this is a successful response (2xx status codes).
     */
    public function isSuccessful(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }

    /**
     * Check if this is an error response (4xx or 5xx status codes).
     */
    public function isError(): bool
    {
        return $this->statusCode >= 400;
    }
}
