<?php

declare(strict_types=1);

namespace Watcher\Utils;

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

    /**
     * Initialize path resolver based on entry point file location.
     *
     * @param string $entryPointFile Absolute path to the entry point file (__FILE__)
     */
    public function __construct(string $entryPointFile)
    {
        $realPath = realpath($entryPointFile);
        if ($realPath === false) {
            throw new InvalidArgumentException('Entry point file not found: ' . $entryPointFile);
        }
        $this->appPath = dirname($realPath, 2);
        $rootPath = dirname($this->appPath);
        if ($rootPath === '.' || $rootPath === '') {
            throw new RuntimeException('Cannot resolve root path from: ' . $this->appPath);
        }
        $this->rootPath = $rootPath;
        $this->logsPath = $this->appPath . DIRECTORY_SEPARATOR . 'logs';
        $this->tempPath = $this->appPath . DIRECTORY_SEPARATOR . 'temp';
        $this->publicPath = $this->appPath . DIRECTORY_SEPARATOR . 'public';
        $this->configPath = $this->appPath . DIRECTORY_SEPARATOR . 'config';
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
