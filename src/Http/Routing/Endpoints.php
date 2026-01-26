<?php

declare(strict_types=1);

namespace Mata\Http\Routing;

use InvalidArgumentException;

/**
 * Centralizes route endpoints for easy access in templates and HTMX interactions.
 */
class Endpoints
{
    /** @var array<string,string> */
    private array $routes = [];

    /**
     * Register a named route endpoint.
     *
     * @param string $name Unique identifier for the route
     * @param string $path The relative route path/URL
     */
    public function register(string $name, string $path): void
    {
        $this->routes[$name] = $path;
    }

    /**
     * Get a specific route by name.
     *
     * @param string $name The route name
     *
     * @return null|string The route path or null if not found
     *
     * @deprecated Use url() instead for consistency
     */
    public function get(string $name): ?string
    {
        return $this->routes[$name] ?? null;
    }

    /**
     * Generate a URL for a named route with optional parameters and query string.
     *
     * Substitutes placeholders like {server}, {metric} with provided values.
     * Automatically handles URL encoding for parameter values.
     * Optionally appends query string parameters.
     *
     * @param string               $name   Route name
     * @param array<string,string> $params Parameters to substitute into route placeholders
     * @param array<string,mixed>  $query  Optional query string parameters
     *
     * @return null|string The generated URL or null if route not found
     *
     * @throws InvalidArgumentException If route has unsubstituted placeholders
     *
     * @example $endpoints->url('login') // '/login'
     * @example $endpoints->url('metrics_server', ['server' => 'prod', 'metric' => 'cpu']) // '/api/metrics/prod/cpu'
     * @example $endpoints->url('metrics_server', ['server' => 'prod', 'metric' => 'cpu'], ['granularity' => 'hourly']) // '/api/metrics/prod/cpu?granularity=hourly'
     */
    public function url(string $name, array $params = [], array $query = []): ?string
    {
        $route = $this->routes[$name] ?? null;

        if ($route === null) {
            return null;
        }

        // Replace placeholders with URL-encoded parameter values
        $url = $route;
        if ($params !== []) {
            foreach ($params as $key => $value) {
                $placeholder = '{' . $key . '}';
                $url = str_replace($placeholder, rawurlencode((string) $value), $url);
            }
        }

        // Check for unsubstituted placeholders
        if (preg_match('/\{([^}]+)\}/', $url, $matches)) {
            throw new InvalidArgumentException(
                sprintf(
                    'Route "%s" has unsubstituted placeholder "{%s}". Route pattern: "%s"',
                    $name,
                    $matches[1],
                    $route
                )
            );
        }

        // Append query string if provided
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        return $url;
    }

    /**
     * Get all registered routes.
     *
     * @return array<string,string> All route name => path mappings
     */
    public function all(): array
    {
        return $this->routes;
    }

    /**
     * Get only specified routes by their names.
     *
     * @param array<int,string> $names Array of route names to retrieve
     *
     * @return array<string,string> Filtered route name => path mappings
     */
    public function only(array $names): array
    {
        return array_intersect_key($this->routes, array_flip($names));
    }
}
