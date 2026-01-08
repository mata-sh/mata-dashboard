<?php

declare(strict_types=1);

namespace Watcher\Views;

use Monolog\Logger;
use Twig\Environment;
use Watcher\Core\App;
use Watcher\Utils\Tools;

/**
 * Base renderer class providing common functionality for view renderers.
 *
 * Provides shared dependencies and utilities for rendering HTML components:
 * - Twig templating engine access
 * - Logging capabilities
 * - Utility functions for formatting and icons
 * - HTML ID generation utilities
 */
abstract class BaseRenderer
{
    protected Environment $twig;
    protected Logger $logger;
    protected Tools $tools;
    protected App $app;

    public function __construct(
        Environment $twig,
        Logger $logger,
        Tools $tools,
        App $app
    ) {
        $this->twig = $twig;
        $this->logger = $logger;
        $this->tools = $tools;
        $this->app = $app;
    }

    // ────────────────────────────────────────────────
    // General helpers
    // ────────────────────────────────────────────────

    /**
     * Generate HTML-safe ID for elements.
     *
     * @param string $name   Primary identifier
     * @param string $suffix Optional suffix
     *
     * @return string HTML-safe identifier
     */
    protected function generateHtmlId(string $name, string $suffix = ''): string
    {
        $fullName = $name . ($suffix ? "-{$suffix}" : '');

        return preg_replace('/[^a-z0-9]+/', '-', strtolower($fullName));
    }

    /**
     * Safely escape HTML content.
     *
     * @param mixed $content Content to escape
     *
     * @return string Escaped HTML-safe content
     */
    protected function escape($content): string
    {
        return htmlspecialchars((string) $content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Truncate text to specified length with indicator.
     *
     * @param string $text      Text to truncate
     * @param int    $maxLength Maximum length (default: 15)
     * @param string $indicator Truncation indicator (default: '…')
     *
     * @return string Truncated text with indicator if needed
     */
    protected function truncate(string $text, int $maxLength = 20, string $indicator = '…'): string
    {
        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        return mb_substr($text, 0, $maxLength - mb_strlen($indicator)) . $indicator;
    }

    // ────────────────────────────────────────────────
    // Logging
    // ────────────────────────────────────────────────

    /**
     * Log rendering errors with context.
     *
     * @param string              $message   Error message
     * @param string              $component Component being rendered
     * @param array<string,mixed> $context   Additional context
     */
    protected function logError(string $message, string $component, array $context = []): void
    {
        $this->logger->error("Rendering error in {$component}: {$message}", array_merge($context, [
            'component' => $component,
        ]));
    }

    // ────────────────────────────────────────────────
    // Formatting helpers
    // ────────────────────────────────────────────────

    /**
     * Convert bytes to human readable format.
     *
     * @param float|int $bytes Size in bytes
     *
     * @return string Formatted size string
     */
    protected function formatSize(float|int $bytes): string
    {
        return $this->tools->convertSize((int) $bytes);
    }

    /**
     * Format numbers with thousands separators.
     *
     * @param float|int $number   Number to format
     * @param int       $decimals Number of decimal places
     *
     * @return string Formatted number
     */
    protected function formatNumber(float|int $number, int $decimals = 0): string
    {
        return number_format($number, $decimals, '', '.');
    }

    // ────────────────────────────────────────────────
    // Ui assets
    // ────────────────────────────────────────────────

    /**
     * Get common icons used across multiple renderers.
     *
     * @return array<string,string> Icon name to HTML mapping
     */
    protected function getCommonIcons(): array
    {
        return [
            'refresh' => $this->tools->icon('refresh-cw', 12),
            'refresh_ccw' => $this->tools->icon('refresh-ccw', 12),
            'activity' => $this->tools->icon('activity', 12),
            'cpu' => $this->tools->icon('cpu', 12),
            'hard_drive' => $this->tools->icon('hard-drive', 12),
            'pie_chart' => $this->tools->icon('pie-chart', 12),
            'file' => $this->tools->icon('file', 12),
            'database' => $this->tools->icon('database', 12),
            'check_circle' => $this->tools->icon('check-circle', 12),
            'info' => $this->tools->icon('info', 12),
            'alert' => $this->tools->icon('alert-circle', 12),
            'link' => $this->tools->icon('link', 12),
            'user' => $this->tools->icon('user', 12),
            'settings' => $this->tools->icon('settings', 12),
        ];
    }

    /**
     * Build dropdown options for servers from server config.
     *
     * @param array<string,mixed> $serversConfig
     *
     * @return array<int,array{value:string,label:string}>
     */
    protected function buildServerDropdownOptions(array $serversConfig): array
    {
        $servers = [];

        foreach ($serversConfig as $serverKey => $serverConfig) {
            if (!is_array($serverConfig)) {
                continue;
            }

            $serverKey = (string) $serverKey;

            if (str_starts_with($serverKey, '_')) {
                continue;
            }

            $servers[] = [
                'value' => $serverKey,
                'label' => (string) ($serverConfig['title'] ?? $serverKey),
            ];
        }

        usort($servers, static fn (array $a, array $b) => strcmp($a['label'], $b['label']));

        return $servers;
    }

    /**
     * Generate HTML img tag for images.
     *
     * @param string   $src       Image source path or filename
     * @param string   $alt       Alt text for accessibility
     * @param null|int $width     Image width in pixels (optional)
     * @param null|int $height    Image height in pixels (optional)
     * @param string   $class     Additional CSS classes
     * @param string   $subfolder Subfolder within images directory
     * @param bool     $lazy      Enable lazy loading (default: true)
     *
     * @return string HTML img tag or empty string if file not found
     */
    protected function image(
        string $src,
        string $alt = '',
        ?int $width = null,
        ?int $height = null,
        string $class = '',
        string $subfolder = '',
        bool $lazy = true
    ): string {
        // Check if it's a relative path or just a filename
        $isRelativePath = str_contains($src, '/') || str_starts_with($src, 'assets/');

        // Build the filesystem path for validation and URL path for output
        if ($isRelativePath) {
            // User provided a relative path like "assets/images/logo.png"
            $filesystemPath = $this->app->paths->publicPath . '/' . ltrim($src, '/');
            $urlPath = $this->app->getBasePath() . '/' . ltrim($src, '/');
        } else {
            // User provided just a filename, use subfolder structure
            $subfolder = $subfolder ? rtrim($subfolder, '/') . '/' : '';
            $filesystemPath = $this->app->paths->publicPath . "/assets/images/{$subfolder}{$src}";
            $urlPath = $this->app->getBasePath() . "/assets/images/{$subfolder}{$src}";
        }

        // Verify file exists on filesystem
        if (!file_exists($filesystemPath)) {
            $this->logger->warning("Image file not found: {$filesystemPath}");

            return '';
        }

        // Build attributes
        $attributes = [
            'src="' . $this->escape($urlPath) . '"',
            'alt="' . $this->escape($alt) . '"',
        ];

        if ($width !== null) {
            $attributes[] = 'width="' . $width . '"';
        }

        if ($height !== null) {
            $attributes[] = 'height="' . $height . '"';
        }

        if (!empty($class)) {
            $attributes[] = 'class="' . $this->escape($class) . '"';
        }

        if ($lazy) {
            $attributes[] = 'loading="lazy"';
        }

        return '<img ' . implode(' ', $attributes) . '>';
    }
}
