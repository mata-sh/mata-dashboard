<?php

declare(strict_types=1);

namespace Mata\Core;

use Mata\Config\Dto\ApplicationConfig;
use Mata\Http\Request;
use Mata\Http\Security\RateLimiting\RequestRateLimiter;
use Mata\Utils\PathResolver;

/**
 * Main application container with authentication and rate limiting.
 */
class App
{
    public readonly Auth $auth;
    public readonly ApplicationConfig $config;
    public readonly PathResolver $paths;
    public readonly ?RequestRateLimiter $rateLimiter;
    private readonly Request $request;
    private string $basePath = '';

    /**
     * Initialize application with authentication and path management.
     */
    public function __construct(
        Auth $auth,
        ApplicationConfig $config,
        PathResolver $paths,
        Request $request,
        ?RequestRateLimiter $rateLimiter = null,
    ) {
        $this->auth = $auth;
        $this->config = $config;
        $this->paths = $paths;
        $this->rateLimiter = $rateLimiter;
        $this->request = $request;
        $this->detectBasePath();
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

    /** Get the configured application host. */
    public function getBaseHost(): string
    {
        return $this->config->environment->domain;
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
        $protocol = $this->request->protocol();
        $host = $this->request->host() ?? $this->getBaseHost();

        return $protocol . '://' . $host . $this->basePath;
    }

    private function detectBasePath(): void
    {
        $this->basePath = $this->request->scriptDirectory();
    }
}
