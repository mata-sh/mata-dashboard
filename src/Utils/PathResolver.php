<?php

declare(strict_types=1);

namespace Mata\Utils;

use InvalidArgumentException;
use Mata\Config\Dto\ApplicationConfig;
use RuntimeException;

/**
 * Centralized path management across different environments.
 *
 * Provides consistent path resolution for:
 * - Application root directory detection
 * - Logs directory path management
 * - Temporary files directory
 * - Public assets directory
 * - Cross-platform directory separator handling
 * - Environment-aware path calculation
 */
class PathResolver
{
    public string $appPath;
    public string $rootPath;
    public string $logsPath;
    public string $tempPath;
    public string $publicPath;
    public string $configPath;
    public string $templatesPath;
    public string $appLogPath;
    public string $bootLogPath;

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

    private function __construct(string $appPath)
    {
        $this->appPath = $appPath;
        $rootPath = dirname($this->appPath);
        if ($rootPath === '.' || $rootPath === '') {
            throw new RuntimeException('Cannot resolve root path from: ' . $this->appPath);
        }
        $this->rootPath = $rootPath;
        $this->logsPath = $this->appPath . DIRECTORY_SEPARATOR . 'logs';
        $this->appLogPath = $this->logsPath . DIRECTORY_SEPARATOR . 'app.log';
        $this->bootLogPath = $this->resolveBootLogPath();
        $this->tempPath = $this->appPath . DIRECTORY_SEPARATOR . 'temp';
        $this->publicPath = $this->appPath . DIRECTORY_SEPARATOR . 'public';
        $this->configPath = $this->appPath . DIRECTORY_SEPARATOR . 'config';
        $this->templatesPath = $this->appPath . DIRECTORY_SEPARATOR . 'templates';
    }

    /**
     * Apply config overrides for log/temp paths.
     */
    public function applyConfig(ApplicationConfig $config): void
    {
        $logPath = trim($config->logging->path);
        if ($logPath !== '') {
            $this->appLogPath = $this->resolvePath($logPath);
            $this->logsPath = dirname($this->appLogPath);
        }

        $tempDir = trim($config->environment->tempDir);
        if ($tempDir !== '') {
            $this->tempPath = $this->resolvePath($tempDir);
        }

        $this->ensureDirectory($this->logsPath);
        $this->ensureDirectory($this->tempPath);
    }

    /**
     * Ensure a directory exists and is writable; create it if not existing.
     *
     * @throws RuntimeException if the directory is not writable or cannot be created
     */
    private function ensureDirectory(string $path): void
    {
        if (!is_dir($path)) {
            if (!mkdir($path, 0775, true)) {
                throw new RuntimeException("Failed to create directory: {$path}");
            }
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

        if ($this->isAbsolutePath($path)) {
            return $path;
        }

        return $this->appPath . DIRECTORY_SEPARATOR . $path;
    }

    private function isAbsolutePath(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        // Unix-style absolute path
        if ($path[0] === DIRECTORY_SEPARATOR) {
            return true;
        }

        // Windows drive letter paths like C:\ or C:/
        return (bool) preg_match('/^[A-Za-z]:[\\\\\/]/', $path);
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

        $bootPath = $this->logsPath . DIRECTORY_SEPARATOR . 'boot.log';
        $bootDir = dirname($bootPath);

        if (is_dir($bootDir) && is_writable($bootDir)) {
            return $bootPath;
        }

        return sys_get_temp_dir() . 'mata-boot.log';
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

        return $this->configPath . DIRECTORY_SEPARATOR . $filename;
    }

    /**
     * Convert absolute paths to project-relative representation.
     *
     * Normalizes path separators and converts absolute paths to relative
     * paths from the application root directory.
     *
     * @param string $absolutePath Absolute file system path
     *
     * @return string Relative path from app root
     */
    public function toRelativePath(string $absolutePath): string
    {
        $normalizedPath = str_replace('\\', '/', $absolutePath);
        $normalizedAppPath = rtrim(str_replace('\\', '/', $this->appPath), '/') . '/';

        if (str_starts_with($normalizedPath, $normalizedAppPath)) {
            return ltrim(substr($normalizedPath, strlen($normalizedAppPath)), '/');
        }

        return ltrim($normalizedPath, '/');
    }
}
