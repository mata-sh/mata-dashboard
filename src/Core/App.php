<?php

declare(strict_types=1);

namespace Mata\Core;

use Mata\Config\Dto\ApplicationConfig;
use Mata\Http\Request;
use Mata\Http\Responses\RedirectResponse;
use Mata\Http\Security\RateLimiting\RequestRateLimiter;
use Mata\Services\Auth\UserAuthenticator;
use Mata\Utils\PathResolver;

/**
 * Main application container with authentication and rate limiting.
 */
class App
{
    public readonly UserAuthenticator $auth;
    public readonly ApplicationConfig $config;
    public readonly PathResolver $paths;
    public readonly ?RequestRateLimiter $rateLimiter;
    private readonly Request $request;
    private string $basePath = '';

    /**
     * Initialize application with authentication and path management.
     */
    public function __construct(
        UserAuthenticator $auth,
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
     * A redirect to an absolute URL or path as given, or to an application route such as 'watch'.
     *
     * Served from /public (native development), the route stays relative so the browser resolves
     * it below /public instead of bypassing .htaccess.
     */
    public function redirect(string $url): RedirectResponse
    {
        if (preg_match('/^(https?:\/\/|\/)/i', $url)) {
            return new RedirectResponse($url);
        }

        $route = ltrim($url, '/');

        return new RedirectResponse($this->basePath === '/public' ? $route : '/' . $route);
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
