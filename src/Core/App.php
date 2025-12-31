<?php

declare(strict_types=1);

namespace Watcher\Core;

use Watcher\Utils\PathResolver;

/**
 * Main application container with authentication and rate limiting.
 */
class App
{
    public Auth $auth;
    public PathResolver $paths;
    public string $baseUrl = '';
    private string $basePath = '';

    /**
     * Initialize application with authentication and path management.
     */
    public function __construct(Auth $auth, PathResolver $paths)
    {
        $this->paths = $paths;
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $this->auth = $auth;
        $this->detectBasePath();
    }

    /**
     * Auto-detect base URL path from HTTP request.
     *
     * Handles different deployment scenarios:
     * - Production: mata.mydomain.com/ → SCRIPT_NAME: /index.php → basePath: ''
     * - Development: localhost:8001/public/ → SCRIPT_NAME: /public/index.php → basePath: '/public'
     */
    private function detectBasePath(): void
    {
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
        $scriptDir = dirname($scriptName);
        // If we're in root directory, no base path needed
        $this->basePath = ($scriptDir === '/') ? '' : $scriptDir;
    }

    /**
     * Auto-detect protocol from current request.
     *
     * @return string 'http' or 'https'
     */
    private function detectProtocol(): string
    {
        // Check if HTTPS is enabled
        if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return 'https';
        }

        // Check REQUEST_SCHEME (more reliable than HTTPS header)
        if (isset($_SERVER['REQUEST_SCHEME'])) {
            return $_SERVER['REQUEST_SCHEME'];
        }

        // Check if forwarded from proxy (common in Docker/cloud setups)
        if (isset($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
            return $_SERVER['HTTP_X_FORWARDED_PROTO'];
        }

        // Default to HTTP
        return 'http';
    }

    /**
     * Require user authentication, redirect to login if not authenticated.
     */
    public function requireAuth(): void
    {
        if (!$this->auth->check()) {
            $this->redirect($this->paths->appPath . 'login');
        }
    }

    /**
     * Redirect user to specified URL and terminate execution.
     *
     * @param string $url Target URL for redirection (relative path like 'watch' or absolute URL)
     */
    public function redirect(string $url): void
    {
        // If URL is already absolute (starts with http/https or /), use as-is
        if (preg_match('/^(https?:\/\/|\/)/i', $url)) {
            header('Location: ' . $url);

            exit;
        }

        // For development (/public/), use relative redirect to avoid .htaccess bypass
        if ($this->basePath === '/public') {
            // Relative redirect: 'login' stays as 'login', browser resolves to /public/login
            header('Location: ' . ltrim($url, '/'));
        } else {
            // For production (/), use absolute path
            header('Location: /' . ltrim($url, '/'));
        }

        exit;
    }

    /**
     * Check if user is currently authenticated.
     */
    public function isLoggedIn(): bool
    {
        return $this->auth->check();
    }

    /**
     * Get current authenticated user data.
     *
     * @return null|array<string,mixed> User data array or null if not authenticated
     */
    public function user(): ?array
    {
        return $this->auth->getUser();
    }

    /**
     * Check and apply rate limiting for requests.
     *
     * Implements file-based rate limiting with sliding window.
     *
     * @param string $key    Unique key for rate limiting (IP, user, endpoint)
     * @param int    $limit  Maximum requests allowed
     * @param int    $window Time window in seconds
     *
     * @return bool True if rate limit exceeded, false if within limits
     */
    public function isRateLimited(string $key, int $limit = 100, int $window = 60): bool
    {
        $dir = sys_get_temp_dir() . '/watcher_rate_limiting/';
        if (!is_dir($dir)) {
            // try {
            mkdir($dir, 0777, true);
            // } catch (Exception) {

            // }
        }

        $file = $dir . md5($key) . '.log';
        $now = time();
        $timestamps = [];

        if (file_exists($file)) {
            $timestamps = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $timestamps = $timestamps !== false ? array_map('intval', $timestamps) : [];
            // Filter out old entries
            $timestamps = array_filter($timestamps, fn ($t) => $t > ($now - $window));
        }

        if (count($timestamps) >= $limit) {
            return true; // rate limit exceeded
        }

        $timestamps[] = $now;
        file_put_contents($file, implode("\n", $timestamps));

        return false;
    }

    /**
     * Set base URL for the application.
     *
     * @param string $url Base URL including protocol and domain
     */
    public function setBaseUrl(string $url): void
    {
        $this->baseUrl = $url;
    }

    /**
     * Get configured base URL for the application.
     *
     * @return string Base URL or empty string if not set
     */
    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * Get the detected base path for URL construction.
     *
     * @return string Base path (e.g., '/public' or '')
     */
    public function getBasePath(): string
    {
        return $this->basePath;
    }

    /**
     * Get full base URL with correct protocol and path.
     *
     * @return string Full URL like 'http://localhost:8001/public' or 'https://mata.mydomain.com'
     */
    public function getFullBaseUrl(): string
    {
        $protocol = $this->detectProtocol();
        $host = $_SERVER['HTTP_HOST'] ?? $this->baseUrl;

        return $protocol . '://' . $host . $this->basePath;
    }
}
