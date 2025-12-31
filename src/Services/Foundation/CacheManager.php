<?php

declare(strict_types=1);

namespace Watcher\Services\Foundation;

use Psr\Log\LoggerInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Centralized cache management service.
 *
 * Provides comprehensive caching functionality for API responses and data:
 * - Structured cache directory management
 * - TTL-based cache validation and expiration
 * - Stale cache fallback for resilient operation
 * - Cache metadata tracking and statistics
 * - URL-safe key generation for filesystem compatibility
 *
 * Features:
 * - Configurable cache TTL with force refresh support
 * - Automatic cache directory creation and organization
 * - Graceful handling of cache read/write failures
 * - Support for both permanent and temporary caching
 * - Cache age and TTL tracking for monitoring
 */
class CacheManager
{
    private LoggerInterface $logger;
    private bool $isCachingEnabled;
    private ?string $cacheDir;
    private int $cacheAge;
    private int $cacheTtl;

    /** @var array<string,int> */
    private array $ttlConfig;

    /**
     * Initialize cache manager with directory, logging, and TTL configuration.
     *
     * @param LoggerInterface   $logger    PSR-3 logger for cache operations and debugging
     * @param null|string       $cacheDir  Path to cache directory (null = disable caching)
     * @param array<string,int> $ttlConfig TTL configuration array with cache timeouts
     */
    public function __construct(LoggerInterface $logger, ?string $cacheDir = null, array $ttlConfig = [])
    {
        $this->logger = $logger;
        $this->isCachingEnabled = $cacheDir !== null;
        $this->cacheDir = $cacheDir;
        $this->cacheAge = 0;
        $this->cacheTtl = 0;
        $this->ttlConfig = $ttlConfig;
    }

    /**
     * Generate structured cache file path for API responses.
     *
     * Creates a hierarchical cache directory based on host, method, and path.
     * Ensures safe filesystem paths by sanitizing input parameters.
     *
     * @param string $apiBase Base API URL for host extraction
     * @param string $method  HTTP method (GET, POST, etc.)
     * @param string $path    API endpoint path
     *
     * @return null|string Full path to cache file or null if caching disabled
     */
    public function generateCachePath(string $apiBase, string $method, string $path): ?string
    {
        if (!$this->isCachingEnabled || $this->cacheDir === null) {
            return null;
        }

        $host = $this->extractHostFromApiBase($apiBase);
        $safeHost = $this->sanitizeForFilesystem($host, 'unknown_host');
        $safePath = $this->sanitizePath($path);
        $method = strtoupper($method);

        $dirnamePath = $this->normalizeCacheDirectoryPath($safePath);
        $fullDir = "{$this->cacheDir}/{$safeHost}/{$method}/" . $dirnamePath;

        if (!is_dir($fullDir)) {
            mkdir($fullDir, 0777, true);
        }

        $filename = basename($safePath) . '.json';

        return "{$fullDir}/{$filename}";
    }

    /**
     * Check if cache file exists and is within TTL threshold.
     *
     * @param string $cacheFile  Path to cache file
     * @param int    $ttlSeconds Cache TTL in seconds (0 = expired, -1 = permanent)
     *
     * @return bool True if cache is fresh and valid
     */
    public function isFreshCache(string $cacheFile, int $ttlSeconds): bool
    {
        if (!file_exists($cacheFile)) {
            return false;
        }

        $age = time() - filemtime($cacheFile);

        return $ttlSeconds < 0 || $age < $ttlSeconds;
    }

    /**
     * Read data from cache file with metadata.
     *
     * @param string $cacheFile  Path to cache file
     * @param int    $ttlSeconds Cache TTL for metadata
     *
     * @return null|array<string,mixed> Cache data with metadata or null on failure
     */
    public function readCache(string $cacheFile, int $ttlSeconds): ?array
    {
        $content = file_get_contents($cacheFile);
        if ($content === false) {
            $this->logger->warning("Failed to read cache file: {$cacheFile}");

            return null;
        }

        $fileTime = filemtime($cacheFile) ?: time();
        $this->cacheAge = $fileTime;
        $this->cacheTtl = $ttlSeconds;

        return json_decode($content, true);
    }

    /**
     * Write data to cache file.
     *
     * @param string              $cacheFile Path to cache file
     * @param array<string,mixed> $data      Data to cache
     *
     * @return bool True on success, false on failure
     */
    public function writeCache(string $cacheFile, array $data): bool
    {
        $result = file_put_contents($cacheFile, json_encode($data));
        if ($result === false) {
            $this->logger->warning("Failed to write cache file: {$cacheFile}");

            return false;
        }

        $this->cacheAge = time();

        return true;
    }

    /**
     * Reset cache TTL values to force immediate refresh.
     *
     * Sets all TTL values to 0 to force cache invalidation and immediate data refresh.
     */
    public function resetCacheTtl(): void
    {
        $resetKeys = ['apps', 'serverData', 'ping', 'databases', 'appLogs', 'serverProcesses', 'readme', 'errorlog'];

        foreach ($resetKeys as $key) {
            if (isset($this->ttlConfig[$key])) {
                $this->ttlConfig[$key] = 0;
            }
        }
    }

    /**
     * Convert URL to filesystem-safe cache key.
     *
     * Creates a base64-encoded key safe for use as filename.
     * Replaces URL-unsafe characters and removes padding.
     *
     * @param string $url URL to convert to cache key
     *
     * @return string URL-safe base64 encoded key
     */
    public function urlToKey(string $url): string
    {
        return rtrim(strtr(base64_encode($url), '+/', '-_'), '=');
    }

    /**
     * Convert cache key back to original URL.
     *
     * Reverses the urlToKey operation by restoring base64 padding
     * and decoding the key back to the original URL.
     *
     * @param string $key Base64-encoded cache key from urlToKey()
     *
     * @return string Original URL
     */
    public function keyToUrl(string $key): string
    {
        $key = strtr($key, '-_', '+/');

        return base64_decode(
            str_pad(
                $key,
                strlen($key) % 4 === 0 ? strlen($key) : strlen($key) + 4 - strlen($key) % 4,
                '=',
                STR_PAD_RIGHT
            )
        );
    }

    /**
     * Get the age of the last cached response.
     *
     * @return int Cache age timestamp or 0 if not set
     */
    public function getCacheAge(): int
    {
        return $this->cacheAge;
    }

    /**
     * Get the TTL (Time To Live) of the current cache.
     *
     * @return int Cache TTL in seconds
     */
    public function getCacheTtl(): int
    {
        return $this->cacheTtl;
    }

    /**
     * Set cache TTL for metadata tracking.
     *
     * @param int $ttlSeconds TTL value in seconds
     */
    public function setCacheTtl(int $ttlSeconds): void
    {
        $this->cacheTtl = $ttlSeconds;
    }

    /**
     * Check if caching is enabled.
     *
     * @return bool True if caching is enabled and directory is set
     */
    public function isCachingEnabled(): bool
    {
        return $this->isCachingEnabled && $this->cacheDir !== null;
    }

    /**
     * Get cache directory path.
     *
     * @return null|string Cache directory path or null if disabled
     */
    public function getCacheDir(): ?string
    {
        return $this->cacheDir;
    }

    /**
     * Clear all cache files for a specific host.
     *
     * @param string $apiBase Base API URL for host identification
     *
     * @return bool True on success, false on failure
     */
    public function clearHostCache(string $apiBase): bool
    {
        if (!$this->isCachingEnabled || $this->cacheDir === null) {
            return false;
        }

        $host = parse_url($apiBase, PHP_URL_HOST);
        if ($host === false || $host === null) {
            return false;
        }

        $safeHost = $this->sanitizeForFilesystem($host, 'unknown_host');
        $hostCacheDir = "{$this->cacheDir}/{$safeHost}";

        if (!is_dir($hostCacheDir)) {
            return true; // Nothing to clear
        }

        $this->removeDirectoryRecursive($hostCacheDir);
        $this->logger->info("Cleared cache for host: {$host}");

        return true;
    }

    /**
     * Get cache statistics for monitoring.
     *
     * @return array<string,mixed> Cache statistics including file count and total size
     */
    public function getCacheStats(): array
    {
        if (!$this->isCachingEnabled || $this->cacheDir === null || !is_dir($this->cacheDir)) {
            return [
                'enabled' => false,
                'file_count' => 0,
                'total_size_bytes' => 0,
                'directory' => $this->cacheDir,
            ];
        }

        $fileCount = 0;
        $totalSize = 0;

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->cacheDir, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'json') {
                ++$fileCount;
                $totalSize += $file->getSize();
            }
        }

        return [
            'enabled' => true,
            'file_count' => $fileCount,
            'total_size_bytes' => $totalSize,
            'total_size_human' => $this->formatBytes($totalSize),
            'directory' => $this->cacheDir,
        ];
    }

    /**
     * Recursively remove directory and all contents.
     *
     * @param string $dir Directory path to remove
     */
    private function removeDirectoryRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectoryRecursive($path) : unlink($path);
        }
        rmdir($dir);
    }

    /**
     * Get TTL value for a specific cache type.
     *
     * @param string $type Cache type (apps, serverData, databases, etc.)
     *
     * @return int TTL value in seconds, or 0 if type not found
     */
    public function getTtl(string $type): int
    {
        return $this->ttlConfig[$type] ?? 0;
    }

    /**
     * Get all TTL configuration values.
     *
     * @return array<string,int> Complete TTL configuration array
     */
    public function getAllTtl(): array
    {
        return $this->ttlConfig;
    }

    /**
     * Set TTL configuration array.
     *
     * @param array<string,int> $ttlConfig New TTL configuration
     */
    public function setTtlConfig(array $ttlConfig): void
    {
        $this->ttlConfig = $ttlConfig;
    }

    /**
     * Extract hostname from API base URL with fallback handling.
     *
     * Attempts to parse the hostname from a URL. If parsing fails, treats the
     * entire string as a potential hostname and validates it. This handles cases
     * where the API base might be just a domain name without a protocol.
     *
     * @param string $apiBase Base API URL or hostname
     *
     * @return string Extracted or validated hostname, or 'unknown_host' if invalid
     */
    private function extractHostFromApiBase(string $apiBase): string
    {
        // Try to parse as a full URL first
        $host = parse_url($apiBase, PHP_URL_HOST);

        // If parse_url fails, treat the entire string as a potential hostname
        if ($host === false || $host === null) {
            $candidate = trim($apiBase);

            // Validate hostname format: alphanumeric, dots, hyphens, and must contain a dot
            if ($candidate !== ''
                && preg_match('/^[a-z0-9.-]+$/i', $candidate) === 1
                && str_contains($candidate, '.')) {
                return $candidate;
            }

            return 'unknown_host';
        }

        return $host;
    }

    /**
     * Sanitize a string for filesystem use by replacing invalid characters.
     *
     * Replaces any character that's not alphanumeric, underscore, or hyphen with
     * an underscore. Returns the fallback value if sanitization produces null.
     *
     * @param string $value    String to sanitize
     * @param string $fallback Fallback value if sanitization fails
     *
     * @return string Sanitized string safe for filesystem use
     */
    private function sanitizeForFilesystem(string $value, string $fallback): string
    {
        $result = preg_replace('/[^a-z0-9_\-]/i', '_', $value);

        return is_string($result) ? $result : $fallback;
    }

    /**
     * Sanitize API path for cache filesystem structure.
     *
     * Strips leading/trailing slashes, replaces invalid characters with underscores,
     * but preserves internal slashes for directory hierarchy. Returns 'unknown_path'
     * if the result is empty or null.
     *
     * @param string $path API endpoint path
     *
     * @return string Sanitized path safe for filesystem use
     */
    private function sanitizePath(string $path): string
    {
        $safePath = preg_replace('/[^a-z0-9_\-\/]/i', '_', trim($path, '/'));

        if ($safePath === null || $safePath === '') {
            return 'unknown_path';
        }

        return $safePath;
    }

    /**
     * Normalize directory path for cache storage.
     *
     * Extracts the directory portion of a path and normalizes special cases.
     * Returns empty string if the path has no directory component (single file).
     *
     * @param string $path Sanitized cache path
     *
     * @return string Normalized directory path or empty string
     */
    private function normalizeCacheDirectoryPath(string $path): string
    {
        $dirnamePath = dirname($path);

        // dirname() returns '.' for single-level paths with no directory
        if ($dirnamePath === '.' || $dirnamePath === '') {
            return '';
        }

        return $dirnamePath;
    }

    /**
     * Format bytes into human-readable format.
     *
     * @param int $bytes Number of bytes
     *
     * @return string Formatted string (e.g., "1.5 MB")
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $size = $bytes;

        while ($size >= 1024 && $i < 4) {
            $size /= 1024;
            ++$i;
        }

        return round($size, 2) . ' ' . $units[$i];
    }
}
