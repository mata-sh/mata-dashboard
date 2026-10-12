<?php

declare(strict_types=1);

namespace Mata\Services\Cache;

use JsonException;
use Mata\Services\Cache\Dto\CacheEntry;
use Psr\Log\LoggerInterface;

/**
 * JSON filesystem implementation of the cache store contract.
 */
final class FileCacheStore implements CacheStoreInterface
{
    private ?string $cacheDir;

    public function __construct(
        private LoggerInterface $logger,
        ?string $cacheDir = null
    ) {
        $cacheDir = $cacheDir !== null ? trim($cacheDir) : null;
        $this->cacheDir = $cacheDir === '' ? null : $cacheDir;
    }

    public function get(string $key, int $ttl): ?CacheEntry
    {
        if ($ttl === 0) {
            return null;
        }

        $entry = $this->read($key);
        if ($entry === null || $ttl < 0) {
            return $entry;
        }

        return max(0, time() - $entry->storedAt) < $ttl ? $entry : null;
    }

    public function getStale(string $key, int $ttl): ?CacheEntry
    {
        return $ttl === 0 ? null : $this->read($key);
    }

    public function put(string $key, array $value): bool
    {
        if ($this->cacheDir === null) {
            return true;
        }

        try {
            $encoded = json_encode($value, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->logFailure('encode', $key, $e->getMessage());

            return false;
        }

        $path = $this->resolvePath($key, true);
        if ($path === null) {
            return false;
        }

        $directory = dirname($path);
        $temporary = @tempnam($directory, '.cache-');
        if ($temporary === false) {
            $this->logFailure('create_temp', $key, 'Could not create temporary cache file.');

            return false;
        }

        try {
            $written = @file_put_contents($temporary, $encoded, LOCK_EX);
            if ($written === false || $written !== strlen($encoded)) {
                $this->logFailure('write', $key, 'Could not completely write temporary cache file.');

                return false;
            }

            @chmod($temporary, 0664);

            if (!@rename($temporary, $path)) {
                $this->logFailure('rename', $key, 'Could not replace cache file atomically.');

                return false;
            }

            return true;
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    public function delete(string $key): bool
    {
        if ($this->cacheDir === null) {
            return true;
        }

        $path = $this->resolvePath($key, false);
        if ($path === null || !is_file($path)) {
            return true;
        }

        if (!@unlink($path)) {
            $this->logFailure('delete', $key, 'Could not delete cache file.');

            return false;
        }

        return true;
    }

    private function read(string $key): ?CacheEntry
    {
        if ($this->cacheDir === null) {
            return null;
        }

        $path = $this->resolvePath($key, false);
        if ($path === null || !is_file($path)) {
            return null;
        }

        if (!is_readable($path)) {
            $this->logFailure('read', $key, 'Cache file is not readable.');

            return null;
        }

        $content = @file_get_contents($path);
        if ($content === false) {
            $this->logFailure('read', $key, 'Could not read cache file.');

            return null;
        }

        try {
            $value = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->logFailure('decode', $key, $e->getMessage());

            return null;
        }

        if (!is_array($value)) {
            $this->logFailure('decode', $key, 'Cache payload must be an array.');

            return null;
        }

        $stat = @stat($path);
        if ($stat === false || !isset($stat['mtime']) || !is_int($stat['mtime'])) {
            $this->logFailure('stat', $key, 'Could not read cache file modification time.');

            return null;
        }

        return new CacheEntry($value, $stat['mtime']);
    }

    private function resolvePath(string $key, bool $createDirectory): ?string
    {
        if ($this->cacheDir === null) {
            return null;
        }

        try {
            $parts = json_decode($key, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->logFailure('resolve', $key, $e->getMessage());

            return null;
        }

        if (!is_array($parts)
            || !is_string($parts['api_base'] ?? null)
            || !is_string($parts['method'] ?? null)
            || !is_string($parts['path'] ?? null)) {
            $this->logFailure('resolve', $key, 'Invalid API cache key.');

            return null;
        }

        $host = $this->extractHost($parts['api_base']);
        $method = $this->sanitizeSegment(strtoupper($parts['method']), 'UNKNOWN_METHOD');
        $pathSegments = $this->sanitizePath($parts['path']);
        $directory = rtrim($this->cacheDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
            . $this->sanitizeSegment($host, 'unknown_host') . DIRECTORY_SEPARATOR . $method;
        $filename = array_pop($pathSegments);

        foreach ($pathSegments as $segment) {
            $directory .= DIRECTORY_SEPARATOR . $segment;
        }

        if (!$this->isWithinCacheDirectory($directory)) {
            $this->logFailure('resolve', $key, 'Resolved cache path is outside the cache directory.');

            return null;
        }

        if ($createDirectory && !is_dir($directory)) {
            if (!@mkdir($directory, 0775, true) && !is_dir($directory)) {
                $this->logFailure('mkdir', $key, 'Could not create cache directory.');

                return null;
            }
        }

        return $directory . DIRECTORY_SEPARATOR . $filename . '.json';
    }

    /**
     * @return list<string>
     */
    private function sanitizePath(string $path): array
    {
        $segments = explode('/', trim($path, '/'));
        if ($segments === ['']) {
            return ['unknown_path'];
        }

        return array_map(fn (string $segment): string => $this->sanitizeSegment($segment, 'unknown_segment'), $segments);
    }

    private function sanitizeSegment(string $value, string $fallback): string
    {
        $value = preg_replace('/[^a-z0-9_-]/i', '_', $value) ?? '';

        return $value === '' || $value === '.' || $value === '..' ? $fallback : $value;
    }

    private function extractHost(string $apiBase): string
    {
        $host = parse_url($apiBase, PHP_URL_HOST);
        if (is_string($host) && $host !== '') {
            return $host;
        }

        $candidate = trim($apiBase);
        if (preg_match('/^[a-z0-9.-]+$/i', $candidate) === 1 && str_contains($candidate, '.')) {
            return $candidate;
        }

        return 'unknown_host';
    }

    private function isWithinCacheDirectory(string $directory): bool
    {
        $root = rtrim($this->cacheDir ?? '', '/');

        return $directory === $root
            || str_starts_with($directory, $root . DIRECTORY_SEPARATOR);
    }

    private function logFailure(string $operation, string $key, string $error): void
    {
        $this->logger->warning('cache.operation_failed', [
            'operation' => $operation,
            'key' => $key,
            'error' => $error,
        ]);
    }
}
