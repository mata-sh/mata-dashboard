<?php

declare(strict_types=1);

namespace Mata\Utils;

use InvalidArgumentException;
use RuntimeException;

/**
 * Centralized path management across different environments.
 *
 * Provides consistent path resolution for:
 * - Application root directory detection
 * - Logs directory path management
 * - Temporary files directory
 * - Public assets directory
 * - Instance configuration (config/) and its templates (config.example/)
 * - Cross-platform directory separator handling
 * - Environment-aware path calculation
 */
class PathResolver
{
    public readonly string $appPath;
    public readonly string $rootPath;
    public readonly string $logsPath;
    public readonly string $cronLogsPath;
    public readonly string $tempPath;
    public readonly string $apiCachePath;
    public readonly string $publicPath;
    public readonly string $configPath;
    public readonly string $configExamplePath;
    public readonly string $templatesPath;
    public readonly string $appLogPath;
    public readonly string $bootLogPath;

    public static function fromEntryPoint(string $file): self
    {
        $realPath = realpath($file);
        if ($realPath === false) {
            throw new InvalidArgumentException('Entry point not found: ' . $file);
        }
        if (!is_file($realPath)) {
            throw new InvalidArgumentException('Entry point is not a file: ' . $file);
        }

        return new self(dirname($realPath, 2));
    }

    public static function fromAppPath(string $dir): self
    {
        $realPath = realpath($dir);
        if ($realPath === false) {
            throw new InvalidArgumentException('Application directory not found: ' . $dir);
        }
        if (!is_dir($realPath)) {
            throw new InvalidArgumentException('Application path is not a directory: ' . $dir);
        }

        return new self($realPath);
    }

    private function __construct(string $appPath, string $logFile = '', string $tempDir = '', ?string $bootLogPath = null)
    {
        $this->appPath = $appPath;
        $rootPath = dirname($this->appPath);
        if ($rootPath === '.' || $rootPath === '') {
            throw new RuntimeException('Cannot resolve root path from: ' . $this->appPath);
        }

        $this->rootPath = $rootPath;
        $this->appLogPath = $logFile !== ''
            ? $this->resolvePath($logFile)
            : $this->appPath . '/logs/app.log';
        $this->logsPath = dirname($this->appLogPath);
        $this->cronLogsPath = $this->logsPath . '/cron';
        $this->bootLogPath = $bootLogPath ?? $this->resolveBootLogPath();
        $this->tempPath = $tempDir !== ''
            ? $this->resolvePath($tempDir)
            : $this->appPath . '/temp';
        $this->apiCachePath = $this->tempPath . '/api-cache';
        $this->publicPath = $this->appPath . '/public';
        $this->configPath = $this->appPath . '/config';
        $this->configExamplePath = $this->appPath . '/config.example';
        $this->templatesPath = $this->appPath . '/templates';
    }

    /**
     * Apply APP_LOG_PATH and TEMP_DIR; every entry point must, or their caches and locks diverge.
     * Relative values resolve against the app path, empty ones keep the defaults.
     */
    public function withOverrides(string $logFile, string $tempDir): self
    {
        return new self($this->appPath, trim($logFile), trim($tempDir), $this->bootLogPath);
    }

    /**
     * Create the logs directory if missing; every entry point must, since all of them log.
     *
     * @throws RuntimeException if it cannot be created or is not writable
     */
    public function ensureLogsDirectory(): void
    {
        $this->ensureDirectory($this->logsPath);
    }

    /**
     * Create the temp directory if missing; every entry point must, since all of them cache or lock there.
     *
     * @throws RuntimeException if it cannot be created or is not writable
     */
    public function ensureTempDirectory(): void
    {
        $this->ensureDirectory($this->tempPath);
    }

    /**
     * Create the cron logs directory if missing; heartbeats and PHP's error_log do not create it.
     * Only cron jobs call this, so the web and CLI never need write access to it.
     *
     * @throws RuntimeException if it cannot be created or is not writable
     */
    public function ensureCronLogsDirectory(): void
    {
        $this->ensureDirectory($this->cronLogsPath);
    }

    /**
     * Ensure a directory exists and is writable; create it if not existing.
     *
     * @throws RuntimeException if the directory is not writable or cannot be created
     */
    private function ensureDirectory(string $path): void
    {
        // Concurrent cron jobs may create the directory between the check and mkdir().
        if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
            throw new RuntimeException("Failed to create directory: {$path}");
        }

        if (!is_writable($path)) {
            throw new RuntimeException("Directory is not writable: {$path}");
        }
    }

    /**
     * Resolve a path, treating relative paths as app-root relative.
     */
    private function resolvePath(string $path): string
    {
        if ($path === '') {
            return $this->appPath;
        }

        if (str_starts_with($path, '/')) {
            return $path;
        }

        return $this->appPath . '/' . $path;
    }

    private function resolveBootLogPath(): string
    {
        $envAppLogPath = $this->getSystemEnv('APP_LOG_PATH');
        if ($envAppLogPath !== null) {
            $candidate = $this->resolvePath($envAppLogPath);
            $dir = dirname($candidate);
            if (is_dir($dir) && is_writable($dir)) {
                return $candidate;
            }
        }

        $bootPath = $this->logsPath . '/boot.log';
        $bootDir = dirname($bootPath);

        if (is_dir($bootDir) && is_writable($bootDir)) {
            return $bootPath;
        }

        return sys_get_temp_dir() . '/mata-boot.log';
    }

    private function getSystemEnv(string $key): ?string
    {
        $value = $_ENV[$key] ?? getenv($key);
        if (!is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }

    /**
     * Get the full path to a config file.
     *
     * @param string $filename Configuration file name
     *
     * @return string Full path to config file
     */
    public function getConfigPath(string $filename = ''): string
    {
        if ($filename === '') {
            return $this->configPath;
        }

        return $this->configPath . '/' . $filename;
    }

    /**
     * Convert an absolute path to one relative to the app root; paths outside it lose their leading slash.
     */
    public function toRelativePath(string $absolutePath): string
    {
        $appPrefix = rtrim($this->appPath, '/') . '/';

        if (str_starts_with($absolutePath, $appPrefix)) {
            return ltrim(substr($absolutePath, strlen($appPrefix)), '/');
        }

        return ltrim($absolutePath, '/');
    }
}
