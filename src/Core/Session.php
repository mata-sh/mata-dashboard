<?php

declare(strict_types=1);

namespace Mata\Core;

/**
 * Manage secure PHP sessions.
 */
final class Session
{
    /**
     * Start session with secure cookie defaults.
     */
    public static function start(): void
    {
        if (session_status() !== PHP_SESSION_NONE) {
            return;
        }

        $params = session_get_cookie_params();
        $sameSite = self::sameSite($params);

        session_set_cookie_params([
            'lifetime' => $params['lifetime'],
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => $params['secure'] || self::isSecureRequest(),
            'httponly' => true,
            'samesite' => $sameSite,
        ]);

        session_start();
    }

    /**
     * Detect whether the current request uses HTTPS.
     */
    public static function isSecureRequest(): bool
    {
        if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }

        if (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443) {
            return true;
        }

        return isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https';
    }

    /**
     * Destroy the PHP session cookie.
     */
    public static function destroyCookie(): void
    {
        $sessionName = session_name();
        if (!is_string($sessionName)) {
            return;
        }

        if (session_status() !== PHP_SESSION_ACTIVE && !isset($_COOKIE[$sessionName])) {
            return;
        }

        $params = session_get_cookie_params();
        $sameSite = self::sameSite($params);

        setcookie($sessionName, '', [
            'expires' => time() - 3600,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => $params['secure'] || self::isSecureRequest(),
            'httponly' => true,
            'samesite' => $sameSite,
        ]);
    }

    /**
     * Get configured SameSite value.
     *
     * @param array<string,mixed> $params Session cookie parameters
     */
    private static function sameSite(array $params): string
    {
        return isset($params['samesite']) && is_string($params['samesite']) && $params['samesite'] !== ''
            ? $params['samesite']
            : 'Lax';
    }
}
