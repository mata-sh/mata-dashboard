<?php

declare(strict_types=1);

namespace Mata\Core;

use RuntimeException;

/** Manage the application-owned PHP session lifecycle and storage. */
final class Session
{
    public static function start(?string $savePath = null, bool $secureRequest = false): void
    {
        $status = session_status();
        if ($status === PHP_SESSION_ACTIVE) {
            return;
        }
        if ($status === PHP_SESSION_DISABLED) {
            throw new RuntimeException('PHP sessions are disabled');
        }

        if (headers_sent($file, $line)) {
            throw new RuntimeException("Cannot start session after headers were sent at {$file}:{$line}");
        }

        if ($savePath !== null && session_module_name() === 'files') {
            self::configureSavePath($savePath);
        }

        self::setIni('session.use_strict_mode', '1');
        self::setIni('session.use_only_cookies', '1');
        self::setIni('session.use_cookies', '1');

        $params = session_get_cookie_params();
        if (!session_set_cookie_params([
            'lifetime' => $params['lifetime'],
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => $params['secure'] || $secureRequest,
            'httponly' => true,
            'samesite' => self::sameSite($params),
        ])) {
            throw new RuntimeException('Failed to configure session cookie policy');
        }

        if (!session_start() || session_status() !== PHP_SESSION_ACTIVE) {
            throw new RuntimeException('Failed to start PHP session');
        }
    }

    public static function get(string $key): mixed
    {
        return $_SESSION[$key] ?? null;
    }

    public static function set(string $key, mixed $value): void
    {
        self::assertActive();
        $_SESSION[$key] = $value;
    }

    public static function remove(string $key): void
    {
        self::assertActive();
        unset($_SESSION[$key]);
    }

    public static function clear(): void
    {
        self::assertActive();
        $_SESSION = [];
    }

    public static function regenerate(): void
    {
        self::assertActive();

        if (!session_regenerate_id(true)) {
            throw new RuntimeException('Failed to regenerate session ID');
        }
    }

    public static function close(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE && !session_write_close()) {
            throw new RuntimeException('Failed to close PHP session');
        }
    }

    public static function destroy(bool $secureRequest = false): void
    {
        $sessionName = session_name();
        $params = session_get_cookie_params();
        $_SESSION = [];

        if (session_status() === PHP_SESSION_ACTIVE && !session_destroy()) {
            throw new RuntimeException('Failed to destroy PHP session');
        }

        self::destroyCookie($sessionName, $params, $secureRequest);
    }

    private static function assertActive(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new RuntimeException('Application session has not been started');
        }
    }

    private static function configureSavePath(string $savePath): void
    {
        if ($savePath === '' || is_link($savePath)) {
            throw new RuntimeException("Invalid session save path: {$savePath}");
        }
        if (!is_dir($savePath)) {
            if (!@mkdir($savePath, 0700, true) && !is_dir($savePath)) {
                throw new RuntimeException("Failed to create session save path: {$savePath}");
            }
            if (!chmod($savePath, 0700)) {
                throw new RuntimeException("Failed to secure session save path: {$savePath}");
            }
        }
        if (!is_writable($savePath)) {
            throw new RuntimeException("Session save path is not writable: {$savePath}");
        }
        if (session_save_path($savePath) === false) {
            throw new RuntimeException("Failed to configure session save path: {$savePath}");
        }
    }

    private static function setIni(string $option, string $value): void
    {
        if (ini_set($option, $value) === false || ini_get($option) !== $value) {
            throw new RuntimeException("Failed to configure {$option}");
        }
    }

    /** @param array<string,mixed> $params */
    private static function destroyCookie(string $sessionName, array $params, bool $secureRequest): void
    {
        if ($sessionName === '' || headers_sent()) {
            return;
        }

        setcookie($sessionName, '', [
            'expires' => time() - 3600,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => $params['secure'] || $secureRequest,
            'httponly' => true,
            'samesite' => self::sameSite($params),
        ]);
        unset($_COOKIE[$sessionName]);
    }

    /** @param array<string,mixed> $params */
    private static function sameSite(array $params): string
    {
        return isset($params['samesite']) && is_string($params['samesite']) && $params['samesite'] !== ''
            ? $params['samesite']
            : 'Lax';
    }
}
