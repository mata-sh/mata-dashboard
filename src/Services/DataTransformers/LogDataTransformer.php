<?php

declare(strict_types=1);

namespace Mata\Services\DataTransformers;

use Psr\Log\LoggerInterface;

/**
 * Transforms grouped log data from the Node API into a flat structure
 * suitable for frontend display with source file information.
 */
class LogDataTransformer
{
    private const VALID_LOG_TYPES = ['Error', 'Warning', 'Deprecated', 'Other'];

    public function __construct(
        private ?LoggerInterface $logger = null
    ) {}

    /**
     * Transform grouped logs structure to flat array with source field.
     *
     * @param array<string,mixed> $groupedLogs Logs grouped by file path: ['file_path' => [entries...]]
     *
     * @return list<array<string,mixed>> Flat array with source field added: [['timestamp' => ..., 'type' => ..., 'message' => ..., 'source' => ...]]
     */
    public function flattenGroupedLogs(array $groupedLogs): array
    {
        if ($this->isEmpty($groupedLogs)) {
            return [];
        }

        $flatLogs = [];

        foreach ($groupedLogs as $filePath => $entries) {
            // Skip if entries is not an array or is empty
            if (!is_array($entries) || empty($entries)) {
                continue;
            }

            $normalizedSource = $this->normalizeSourcePath($filePath);

            foreach ($entries as $entry) {
                // Skip malformed entries missing required fields
                if (!$this->isValidEntry($entry)) {
                    if ($this->logger) {
                        $this->logger->warning('Skipping malformed log entry', [
                            'source' => $filePath,
                            'entry' => $entry,
                        ]);
                    }

                    continue;
                }

                // Nodes that group repeats send count and first_seen; older ones one entry per occurrence.
                $flatLogs[] = [
                    'timestamp' => $entry['timestamp'],
                    'type' => $this->normalizeLogType($entry['type']),
                    'message' => $entry['message'],
                    'source' => $normalizedSource,
                    'count' => is_int($entry['count'] ?? null) && $entry['count'] > 0 ? $entry['count'] : 1,
                    'first_seen' => is_string($entry['first_seen'] ?? null) ? $entry['first_seen'] : $entry['timestamp'],
                ];
            }
        }

        // Sort by timestamp descending (newest first)
        usort($flatLogs, function ($a, $b) {
            return strcmp($b['timestamp'], $a['timestamp']);
        });

        return $flatLogs;
    }

    /**
     * Check if grouped logs structure is empty.
     *
     * @param array<string,mixed> $groupedLogs
     */
    public function isEmpty(array $groupedLogs): bool
    {
        if (empty($groupedLogs)) {
            return true;
        }

        // Check if all entries are empty
        foreach ($groupedLogs as $entries) {
            if (is_array($entries) && !empty($entries)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Normalize log type to valid values.
     *
     * @return string One of: Error, Warning, Deprecated, Other
     */
    public function normalizeLogType(string $type): string
    {
        $normalized = ucfirst(strtolower(trim($type)));

        if (in_array($normalized, self::VALID_LOG_TYPES, true)) {
            return $normalized;
        }

        // Map common variations
        $typeMap = [
            'Warn' => 'Warning',
            'Notice' => 'Warning',
            'Fatal' => 'Error',
            'Critical' => 'Error',
            'Info' => 'Other',
            'Debug' => 'Other',
        ];

        return $typeMap[$normalized] ?? 'Other';
    }

    /**
     * Normalize source path for display.
     * Removes common prefixes and normalizes separators.
     *
     * @return string Normalized path
     */
    public function normalizeSourcePath(string $filePath): string
    {
        $path = trim($filePath);

        // Remove common prefixes
        $prefixesToRemove = [
            '/var/www/html/',
            '/var/www/',
            '/var/log/',
            '/home/',
            '/usr/local/',
        ];

        foreach ($prefixesToRemove as $prefix) {
            if (str_starts_with($path, $prefix)) {
                $path = substr($path, strlen($prefix));

                break;
            }
        }

        // Normalize path separators
        $path = str_replace('\\', '/', $path);

        // Remove leading/trailing slashes
        $path = trim($path, '/');

        return $path ?: basename($filePath);
    }

    /**
     * Validate that an entry has all required fields.
     *
     * @param mixed $entry
     */
    private function isValidEntry($entry): bool
    {
        if (!is_array($entry)) {
            return false;
        }

        return isset($entry['timestamp'])
            && isset($entry['type'], $entry['message'])

            && is_string($entry['timestamp'])
            && is_string($entry['type'])
            && is_string($entry['message']);
    }
}
