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

            $data[$appName]['session'] = $this->normalizeSessionData(
                isset($appData['session']) && is_array($appData['session']) ? $appData['session'] : []
            );

            // Validate expected fields
            $expectedAppFields = ['title', 'url', 'env', 'status', 'description', 'session'];
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
     * Normalize session data.
     *
     * @param array<string,mixed> $session Decoded session data
     *
     * @return array<string,mixed> Normalized session data
     */
    public function normalizeSessionData(array $session): array
    {
        $validStatuses = ['ok', 'unconfigured', 'error', 'unsupported'];
        $status = $session['status'] ?? null;
        $type = $session['type'] ?? null;

        if (!is_string($type) || $type === '' || !in_array($status, $validStatuses, true)) {
            $this->logger->warning('Unrecognized session payload', [
                'data_keys' => array_keys($session),
                'type' => $type,
                'status' => $status,
            ]);

            return [
                'type' => 'unknown',
                'status' => 'error',
                'count_last_hour' => null,
                'count_last_day' => null,
                'count_total' => null,
                'path' => null,
                'size_kb' => null,
                'error' => 'unrecognized session payload',
            ];
        }

        $countLastHour = $session['count_last_hour'] ?? null;
        $countLastDay = $session['count_last_day'] ?? null;
        $countTotal = $session['count_total'] ?? null;
        $path = $session['path'] ?? null;
        $sizeKb = $session['size_kb'] ?? null;
        $error = $session['error'] ?? null;

        return [
            'type' => $type,
            'status' => $status,
            'count_last_hour' => $countLastHour === null ? null : (int) $countLastHour,
            'count_last_day' => $countLastDay === null ? null : (int) $countLastDay,
            'count_total' => $countTotal === null ? null : (int) $countTotal,
            'path' => $path === null ? null : (string) $path,
            'size_kb' => $sizeKb === null ? null : (float) $sizeKb,
            'error' => $error === null ? null : (string) $error,
        ];
    }

    /**
     * Normalize app sessions data.
     *
     * @param array<string,mixed> $data Decoded app sessions data
     *
     * @return array<string,mixed> Normalized app sessions data
     */
    public function normalizeAppSessions(array $data): array
    {
        return $this->normalizeSessionData($data);
    }

    /**
     * Normalize server cronjob summary data.
     *
     * @param array<string,mixed> $cronjobs Decoded cronjob summary data
     *
     * @return null|array<string,int> Normalized cronjob summary or null when unrecognized
     */
    private function normalizeCronjobSummary(array $cronjobs): ?array
    {
        $requiredFields = ['total', 'working', 'stale', 'missing'];

        foreach ($requiredFields as $field) {
            if (!isset($cronjobs[$field]) || !is_numeric($cronjobs[$field])) {
                $this->logger->warning('Unrecognized cronjob summary payload', [
                    'data_keys' => array_keys($cronjobs),
                ]);

                return null;
            }
        }

        return [
            'total' => (int) $cronjobs['total'],
            'working' => (int) $cronjobs['working'],
            'stale' => (int) $cronjobs['stale'],
            'missing' => (int) $cronjobs['missing'],
        ];
    }

    /**
     * Normalize server cronjobs data.
     *
     * @param array<mixed> $data Decoded server cronjobs data
     *
     * @return array<int,array<string,mixed>> Normalized server cronjobs data
     */
    public function normalizeServerCronjobs(array $data): array
    {
        if (!empty($data) && !$this->isSequentialArray($data)) {
            $this->logger->warning('Server cronjobs response is not a sequential array', [
                'data_keys' => array_keys($data),
            ]);

            return [];
        }

        $normalized = [];
        foreach ($data as $index => $entry) {
            if (!is_array($entry)) {
                $this->logger->warning('Invalid server cronjob entry, dropping', [
                    'index' => $index,
                    'type' => gettype($entry),
                ]);

                continue;
            }

            $entryIndex = $entry['index'] ?? null;
            $path = $entry['path'] ?? null;
            $mtime = $entry['mtime'] ?? null;
            $ageSeconds = $entry['age_seconds'] ?? null;
            $size = $entry['size'] ?? null;
            $contentTail = $entry['content_tail'] ?? null;

            if (is_array($contentTail)) {
                $contentTail = array_values(array_filter($contentTail, static fn ($line): bool => is_string($line)));
            } else {
                $contentTail = [];
            }

            $normalized[] = [
                'index' => $entryIndex === null ? null : (int) $entryIndex,
                'path' => $path === null ? null : (string) $path,
                'exists' => (bool) ($entry['exists'] ?? false),
                'mtime' => $mtime === null ? null : (string) $mtime,
                'age_seconds' => $ageSeconds === null ? null : (int) $ageSeconds,
                'size' => $size === null ? null : (int) $size,
                'stale' => (bool) ($entry['stale'] ?? false),
                'content_tail' => $contentTail,
            ];
        }

        return $normalized;
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
            'cpu_usage',
            'ram_capacity',
            'ram_used',
            'disk_space_capacity',
            'disk_space_used',
            'total_system_filecount',
            'cronjobs',
        ];

        $missingFields = array_diff($expectedFields, array_keys($data));
        if (!empty($missingFields)) {
            $this->logger->warning('Missing server data fields', [
                'missing_fields' => $missingFields,
                'available_fields' => array_keys($data),
            ]);
        }

        $data['cronjobs'] = $this->normalizeCronjobSummary(isset($data['cronjobs']) && is_array($data['cronjobs']) ? $data['cronjobs'] : []);

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
     * Keep the grouped node shape intact:
     * ['file_path' => [['timestamp' => ..., 'type' => ..., 'message' => ...]]]
     * Non-array groups are dropped with a warning.
     *
     * @param array<string,mixed> $data Decoded logs data
     *
     * @return array<string,mixed> Normalized logs data
     */
    public function normalizeAppLogs(array $data): array
    {
        foreach ($data as $filePath => $entries) {
            if (!is_array($entries)) {
                $this->logger->warning('App log group is not an array, dropping', [
                    'file' => $filePath,
                    'type' => gettype($entries),
                ]);
                unset($data[$filePath]);
            }
        }

        return $data;
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
            str_ends_with($path, '/sessions') => $this->normalizeAppSessions($data),
            str_ends_with($path, 'server-cronjobs') => $this->normalizeServerCronjobs($data),
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
