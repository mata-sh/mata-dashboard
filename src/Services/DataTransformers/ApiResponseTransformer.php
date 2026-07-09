<?php

declare(strict_types=1);

namespace Mata\Services\DataTransformers;

use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * API response data transformation service.
 *
 * Handles parsing and validation of API responses:
 * - JSON response parsing and validation
 * - Data structure transformation
 * - Response format standardization
 * - Error handling for malformed data
 */
class ApiResponseTransformer
{
    private LoggerInterface $logger;

    /**
     * Initialize data transformer.
     *
     * @param LoggerInterface $logger PSR-3 logger for transformation errors
     */
    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Transform raw response body to array data.
     *
     * @param string $responseBody Raw HTTP response body
     * @param string $endpoint     API endpoint for logging context
     *
     * @return array<string,mixed> Parsed and validated array data
     *
     * @throws RuntimeException When response is invalid JSON or not an array
     */
    public function transformResponse(string $responseBody, string $endpoint = ''): array
    {
        if (empty($responseBody)) {
            $this->logger->warning("Empty response body from endpoint: {$endpoint}");

            throw new RuntimeException('Empty response body');
        }

        if (!$this->isValidJsonArray($responseBody)) {
            $this->logger->error("Invalid JSON response from endpoint: {$endpoint}", [
                'response_length' => strlen($responseBody),
                'response_preview' => substr($responseBody, 0, 200),
            ]);

            throw new RuntimeException('Invalid JSON response');
        }

        $data = json_decode($responseBody, true);

        $this->logger->debug('Response transformed successfully', [
            'endpoint' => $endpoint,
            'data_keys' => is_array($data) ? array_keys($data) : [],
            'data_count' => is_array($data) ? count($data) : 0,
        ]);

        return $data;
    }

    /**
     * Validate if string contains valid JSON array data.
     *
     * @param string $json JSON string to validate
     *
     * @return bool True if valid JSON array, false otherwise
     */
    public function isValidJsonArray(string $json): bool
    {
        if (empty($json)) {
            return false;
        }

        $decoded = json_decode($json, true);

        return json_last_error() === JSON_ERROR_NONE && is_array($decoded);
    }

    /**
     * Check if array is sequential (numeric keys starting from 0).
     *
     * @param array<mixed> $array Array to check
     *
     * @return bool True if sequential array, false if associative
     */
    private function isSequentialArray(array $array): bool
    {
        return array_keys($array) === range(0, count($array) - 1);
    }

    /**
     * Normalize log file sizes from JSON strings to integers.
     *
     * Converts string values from JSON decoding to proper integer types
     * for consistent type handling throughout the application.
     *
     * @param array<string,int|string> $logs Raw log data with mixed types
     *
     * @return array<string,int> Normalized log data with integer sizes
     */
    private function normalizeLogSizes(array $logs): array
    {
        $normalized = [];
        foreach ($logs as $path => $size) {
            $normalized[$path] = (int) $size;
        }

        return $normalized;
    }

    /**
     * Normalize applications data.
     *
     * @param array<string,mixed> $data Decoded applications data
     *
     * @return array<string,mixed> Normalized applications data
     */
    public function normalizeAppsData(array $data): array
    {
        // Validate and normalize each app's data structure
        foreach ($data as $appName => $appData) {
            if (!is_array($appData)) {
                $this->logger->warning("Invalid app data structure for: {$appName}");

                continue;
            }

            // Normalize log file sizes to integers
            if (isset($appData['logs']) && is_array($appData['logs'])) {
                $data[$appName]['logs'] = $this->normalizeLogSizes($appData['logs']);
            }

            // Validate expected fields
            $expectedAppFields = ['title', 'url', 'env', 'status'];
            $missingFields = array_diff($expectedAppFields, array_keys($appData));

            if (!empty($missingFields)) {
                $this->logger->debug("App missing optional fields: {$appName}", [
                    'missing_fields' => $missingFields,
                ]);
            }
        }

        return $data;
    }

    /**
     * Normalize server system data.
     *
     * @param array<string,mixed> $data Decoded server system data
     *
     * @return array<string,mixed> Validated server system data
     */
    public function normalizeServerSystemData(array $data): array
    {
        // Validate required server data fields
        $expectedFields = [
            'cpu_usage', 'ram_capacity', 'ram_used',
            'disk_space_capacity', 'disk_space_used', 'total_system_filecount',
        ];

        $missingFields = array_diff($expectedFields, array_keys($data));
        if (!empty($missingFields)) {
            $this->logger->warning('Missing server data fields', [
                'missing_fields' => $missingFields,
                'available_fields' => array_keys($data),
            ]);
        }

        return $data;
    }

    /**
     * Normalize database data.
     *
     * @param array<string,mixed> $data Decoded database data
     *
     * @return array<string,mixed> Normalized database data
     */
    public function normalizeDatabaseData(array $data): array
    {
        // No specific normalization needed currently
        return $data;
    }

    /**
     * Normalize app logs data.
     *
     * @param array<string,mixed> $data Decoded logs data
     *
     * @return array<string,mixed> Normalized logs data
     */
    public function normalizeAppLogs(array $data): array
    {
        // Ensure it's a simple array of strings
        if (!$this->isSequentialArray($data)) {
            $this->logger->warning('App logs response is not a sequential array', [
                'data_type' => gettype($data),
            ]);
        }

        return array_map('strval', $data);
    }

    /**
     * Normalize README data to raw markdown content.
     *
     * @param array<string,mixed> $data Decoded README data
     */
    public function normalizeReadmeData(array $data): string
    {
        if (isset($data['content']) && is_string($data['content'])) {
            return $data['content'];
        }

        $legacyContent = reset($data);
        if (is_string($legacyContent)) {
            return $legacyContent;
        }

        $this->logger->warning('README response did not contain markdown content', [
            'data_keys' => array_keys($data),
        ]);

        return '';
    }

    /**
     * Normalize libraries data.
     *
     * @param array<string,mixed> $data Decoded libraries data
     *
     * @return array<string,mixed> Normalized libraries data
     */
    public function normalizeLibrariesData(array $data): array
    {
        // No specific normalization needed currently
        return $data;
    }

    /**
     * Normalize PHP processes data.
     *
     * @param array<string,mixed> $data Decoded PHP processes data
     *
     * @return array<string,mixed> Normalized PHP processes data
     */
    public function normalizePhpProcesses(array $data): array
    {
        // Validate process data structure
        if ($this->isSequentialArray($data)) {
            foreach ($data as $index => $process) {
                if (!is_array($process)) {
                    $this->logger->warning("Invalid process data at index {$index}");

                    continue;
                }

                $expectedFields = ['user', 'pid', 'ppid', 'cpu', 'mem', 'etime', 'args'];
                $missingFields = array_diff($expectedFields, array_keys($process));

                if (!empty($missingFields)) {
                    $this->logger->debug("Process missing fields at index {$index}", [
                        'missing_fields' => $missingFields,
                    ]);
                }
            }
        }

        return $data;
    }

    /**
     * Normalize API response with endpoint-specific transformations and metadata.
     *
     * @param array<mixed>|string $data       Decoded response data (array or string for backward compatibility)
     * @param string              $path       API endpoint path for routing
     * @param int                 $cacheAge   Cache age in seconds
     * @param int                 $cacheTtl   Cache TTL in seconds
     * @param bool                $fromCache  Whether data came from cache
     * @param bool                $staleCache Whether cache is stale
     *
     * @return array<string,mixed> Normalized response with metadata
     */
    public function normalizeApiResponse(
        array|string $data,
        int $cacheAge = 0,
        int $cacheTtl = 0,
        bool $fromCache = false,
        bool $staleCache = false,
        string $path = '',
    ): array {

        $normalized = match (true) {
            !is_array($data) => $data, // String data -> skip
            $path === '' => $data, // no path -> skip
            $path === 'apps' => $this->normalizeAppsData($data),
            $path === 'server-data' => $this->normalizeServerSystemData($data),
            $path === 'server-databases' => $this->normalizeDatabaseData($data),
            str_contains($path, '/logs/') || str_ends_with($path, '/logs') => $this->normalizeAppLogs($data),
            str_contains($path, '/readme') => $this->normalizeReadmeData($data),
            str_contains($path, '/libraries') => $this->normalizeLibrariesData($data),
            $path === 'server-php' => $this->normalizePhpProcesses($data),
            default => $data, // Unknown endpoint -> skip
        };

        return [
            'result' => $normalized,
            'cache_age' => $cacheAge,
            'cache_ttl' => $cacheTtl,
            'from_cache' => $fromCache,
            'stale_cache' => $staleCache,
        ];
    }
}
