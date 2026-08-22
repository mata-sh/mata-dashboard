<?php

declare(strict_types=1);

namespace Mata\Http;

/** Immutable snapshot of HTTP input. */
final class Request
{
    /**
     * @param array<string,mixed> $server
     * @param array<string,mixed> $query
     * @param array<string,mixed> $post
     * @param array<string,mixed> $cookies
     */
    public function __construct(
        private readonly array $server = [],
        private readonly array $query = [],
        private readonly array $post = [],
        private readonly array $cookies = [],
    ) {}

    public static function fromGlobals(): self
    {
        return new self($_SERVER, $_GET, $_POST, $_COOKIE);
    }

    public function method(): string
    {
        return strtoupper($this->serverString('REQUEST_METHOD') ?? 'GET');
    }

    public function uri(): string
    {
        return $this->serverString('REQUEST_URI') ?? '';
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));

        return $this->serverString($key);
    }

    public function clientIp(): ?string
    {
        return $this->serverString('REMOTE_ADDR');
    }

    public function scriptName(): string
    {
        return $this->serverString('SCRIPT_NAME') ?? '/index.php';
    }

    public function scriptDirectory(): string
    {
        $directory = dirname($this->scriptName());

        return $directory === '/' ? '' : $directory;
    }

    public function host(): ?string
    {
        return $this->serverString('HTTP_HOST');
    }

    public function protocol(): string
    {
        if ($this->hasServerTlsSignal()) {
            return 'https';
        }

        // REQUEST_SCHEME comes from the server, so it outranks the client-supplied forwarding header.
        $scheme = $this->serverString('REQUEST_SCHEME') ?? $this->header('X-Forwarded-Proto');

        return $scheme !== null && strtolower($scheme) === 'https' ? 'https' : 'http';
    }

    /**
     * Whether the request reached the user agent over TLS.
     *
     * X-Forwarded-Proto is honoured because TLS-terminating proxies are the common deployment and
     * often expose nothing else, but it is client-supplied and not yet checked against a
     * trusted-proxy allowlist (see TODO.md, SECURITY).
     */
    public function isSecure(): bool
    {
        return $this->hasServerTlsSignal()
            || strtolower($this->header('X-Forwarded-Proto') ?? '') === 'https';
    }

    /** TLS signals the server controls directly, i.e. everything a client cannot forge. */
    private function hasServerTlsSignal(): bool
    {
        $https = $this->serverString('HTTPS');
        if ($https !== null && $https !== '' && strtolower($https) !== 'off' && $https !== '0') {
            return true;
        }

        $port = $this->server['SERVER_PORT'] ?? null;

        return (is_string($port) || is_int($port)) && (int) $port === 443;
    }

    public function postString(string $key): ?string
    {
        return $this->stringValue($this->post, $key);
    }

    /** @return list<string> */
    public function postList(string $key): array
    {
        return $this->stringList($this->post, $key);
    }

    public function postBool(string $key): bool
    {
        $value = $this->post[$key] ?? null;

        return in_array($value, [true, 1, '1', 'true', 'on'], true);
    }

    public function queryString(string $key): ?string
    {
        return $this->stringValue($this->query, $key);
    }

    /** @return list<string> */
    public function queryList(string $key): array
    {
        return $this->stringList($this->query, $key);
    }

    public function hasQuery(string $key): bool
    {
        return array_key_exists($key, $this->query);
    }

    public function cookie(string $key): ?string
    {
        return $this->stringValue($this->cookies, $key);
    }

    /** @param array<string,mixed> $values */
    private function stringValue(array $values, string $key): ?string
    {
        $value = $values[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /** @param array<string,mixed> $values
     * @return list<string>
     */
    private function stringList(array $values, string $key): array
    {
        $value = $values[$key] ?? null;
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_string'));
    }

    private function serverString(string $key): ?string
    {
        return $this->stringValue($this->server, $key);
    }
}
