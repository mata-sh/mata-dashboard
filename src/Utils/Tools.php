<?php

declare(strict_types=1);

namespace Watcher\Utils;

use RuntimeException;
use Watcher\Traits\Loggable;

/**
 * Common utility functions and helper methods.
 *
 * Provides various utility functions including:
 * - Environment file parsing
 * - SVG icon loading and manipulation
 * - Data formatting (numbers, sizes, durations)
 * - Encryption/decryption utilities
 * - String and array manipulation helpers
 * - File system utilities
 */
class Tools
{
    use Loggable;

    private string $appPath;
    private string $publicPath;

    /**
     * Initialize Tools with application paths.
     *
     * @param string      $appPath    Base application path
     * @param null|string $publicPath Public assets path (defaults to appPath/public)
     */
    public function __construct(string $appPath, ?string $publicPath = null)
    {
        $this->appPath = rtrim($appPath, '/');
        $this->publicPath = $publicPath ?? $this->appPath . '/public';
    }

    /**
     * Load and manipulate SVG icons.
     *
     * @param string $name      Icon filename (without .svg extension)
     * @param int    $size      Icon size in pixels
     * @param string $class     Additional CSS classes
     * @param string $subfolder Subfolder within icons directory
     *
     * @return string SVG markup or empty string if not found
     */
    public function icon(string $name, int $size = 24, string $class = '', $subfolder = ''): string
    {
        $subfolder = $subfolder ? $subfolder . '/' : '';
        $iconPath = $this->publicPath . "/assets/icons/{$subfolder}{$name}.svg";

        if (file_exists($iconPath)) {
            $svg = file_get_contents($iconPath);
            if ($svg === false) {
                $this->log("Could not read icon file: {$iconPath}", 'error');

                return '';
            }
            $svg = preg_replace('/(width|height)="[^"]*"/', '', $svg);
            if ($svg === null) {
                $this->log("preg_replace failed for icon: {$iconPath}", 'error');

                return '';
            }
            $svg = preg_replace(
                '/<svg([^>]*)>/',
                '<svg$1 class="icon ' . $class . '" width="' . $size . '" height="' . $size . '">',
                $svg
            );
            if ($svg === null) {
                $this->log("preg_replace failed for SVG element: {$iconPath}", 'error');

                return '';
            }

            return $svg;
        }

        if ($this->logger !== null) {
            $this->log("Cant find icon: {$iconPath}", 'warning');
        }

        return '';
    }

    /**
     * Convert bytes to human-readable format.
     */
    public function convertSize(int $bytes): string
    {
        $units = ['KB', 'MB', 'GB', 'TB'];
        $size = $bytes / 1024; // Convert bytes to KB first
        $unitIndex = 0;

        while ($size >= 1024 && $unitIndex < count($units) - 1) {
            $size /= 1024;
            ++$unitIndex;
        }

        $intPart = (int) $size;
        $decimals = 2;

        if ($intPart >= 100) {
            $decimals = 0;
        } elseif ($intPart >= 10) {
            $decimals = 1;
        }

        // Format with up to 2 decimals but strip trailing zeros
        if ($decimals === 0) {
            $formatted = number_format($size, 0);
        } else {
            $formatted = rtrim(rtrim(number_format($size, $decimals), '0'), '.');
        }

        return $formatted . ' ' . $units[$unitIndex];
    }

    /**
     * Format duration in seconds to human-readable string.
     */
    public function formatDuration(int $seconds): string
    {
        $minute = 60;
        $hour = 60 * $minute;
        $day = 24 * $hour;
        $week = 7 * $day;
        $month = 30.44 * $day; // average month length
        $year = 365.25 * $day; // average year length
        $unit = '';

        switch (true) {
            case $seconds === -1:
                return '∞';

            case $seconds >= $year:
                $value = $seconds / $year;
                $unit = 'year';

                break;

            case $seconds >= $month:
                $value = $seconds / $month;
                $unit = 'month';

                break;

            case $seconds >= $week:
                $value = $seconds / $week;
                $unit = 'week';

                break;

            case $seconds >= $day:
                $value = $seconds / $day;
                $unit = 'day';

                break;

            case $seconds >= $hour:
                $value = $seconds / $hour;
                $unit = 'hour';

                break;

            case $seconds >= $minute:
                $value = $seconds / $minute;
                $unit = 'minute';

                break;

            default:
                $value = $seconds;
                $unit = 'second';

                break;
        }

        $value = (int) floor($value);

        return "{$value} {$unit}" . ($value !== 1 ? 's' : '');
    }

    /**
     * Encrypt plaintext using AES-256-CBC.
     */
    public function encrypt(string $plaintext, string $key): string
    {
        $iv = openssl_random_pseudo_bytes(16);
        $ciphertext = openssl_encrypt($plaintext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);

        return base64_encode($iv . $ciphertext);
    }

    /**
     * Decrypt encrypted data using AES-256-CBC.
     */
    public function decrypt(string $encrypted, string $key): string
    {
        $data = base64_decode($encrypted);
        $iv = substr($data, 0, 16);
        $ciphertext = substr($data, 16);
        $result = openssl_decrypt($ciphertext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        if ($result === false) {
            throw new RuntimeException('Decryption failed');
        }

        return $result;
    }

    /**
     * Build an HMAC auth token for API requests.
     *
     * Format: server|timestamp|nonce|signature
     */
    public function buildHmacAuthToken(
        string $server,
        string $apiKey,
        int $timestamp,
        ?string $nonce = null
    ): string {
        $nonceValue = $nonce ?? bin2hex(random_bytes(16));
        $payload = $server . '|' . $timestamp . '|' . $nonceValue;
        $signature = hash_hmac('sha256', $payload, $apiKey);

        return $server . '|' . $timestamp . '|' . $nonceValue . '|' . $signature;
    }

    /**
     * Format large numbers with K/M suffix.
     */
    public function formatNumber(float|int $num): string
    {
        if ($num >= 1000000) {
            return round($num / 1000000, 1) . 'M';
        }
        if ($num >= 1000) {
            return round($num / 1000, 1) . 'k';
        }

        return (string) $num;

    }

    /**
     * Normalize a job name for log filenames.
     * Converts snake_case / mixed_case to kebab-case, lowercase, filesystem-safe.
     */
    public function normalizeJobName(string $jobName): string
    {
        // replace underscores and whitespace with hyphens
        $jobName = preg_replace('/[_\s]+/', '-', $jobName);

        // collapse multiple hyphens
        $jobName = preg_replace('/-+/', '-', $jobName);

        // lowercase
        $jobName = strtolower($jobName);

        // trim hyphens
        return trim($jobName, '-');
    }
}
