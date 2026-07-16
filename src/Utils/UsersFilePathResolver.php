<?php

declare(strict_types=1);

namespace Mata\Utils;

use InvalidArgumentException;

/**
 * Resolves the persistent authentication data path.
 */
final class UsersFilePathResolver
{
    /**
     * Use MATA_USERS_FILE when configured; otherwise use the dashboard-wide
     * in-project path for development installations.
     */
    public static function resolve(string $appPath): string
    {
        $configuredPath = self::configuredPath();
        if ($configuredPath === null) {
            return $appPath . '/config/users.json';
        }

        if (!self::isAbsolutePath($configuredPath)) {
            throw new InvalidArgumentException('MATA_USERS_FILE must be an absolute path.');
        }

        $resolvedAppPath = realpath($appPath);
        $resolvedDirectory = self::nearestExistingAncestor(dirname($configuredPath));
        if ($resolvedAppPath !== false && $resolvedDirectory !== false
            && ($resolvedDirectory === $resolvedAppPath
                || str_starts_with($resolvedDirectory, rtrim($resolvedAppPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR))) {
            throw new InvalidArgumentException('MATA_USERS_FILE must be outside the application directory.');
        }

        return $configuredPath;
    }

    private static function nearestExistingAncestor(string $directory): false|string
    {
        while (!is_dir($directory)) {
            $parent = dirname($directory);
            if ($parent === $directory) {
                return false;
            }
            $directory = $parent;
        }

        return realpath($directory);
    }

    private static function configuredPath(): ?string
    {
        $path = $_ENV['MATA_USERS_FILE'] ?? $_SERVER['MATA_USERS_FILE'] ?? getenv('MATA_USERS_FILE');
        if (!is_string($path)) {
            return null;
        }

        $path = trim($path);

        return $path === '' ? null : $path;
    }

    private static function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR)
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }
}
