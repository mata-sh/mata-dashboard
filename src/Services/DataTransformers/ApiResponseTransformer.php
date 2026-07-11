<?php

declare(strict_types=1);

namespace Mata\Services\DataTransformers;

use Mata\Constants\Severities;
use Mata\Utils\TypeConverter;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Parses API JSON and converts endpoint payloads into dashboard contracts.
 */
class ApiResponseTransformer
{
    public function __construct(private LoggerInterface $logger) {}

    /**
     * @return array<string,mixed>
     *
     * @throws RuntimeException When response is invalid JSON or not an array
     */
    public function transformResponse(string $responseBody, string $endpoint = ''): array
    {
        if ($responseBody === '') {
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
        if (!is_array($data)) {
            throw new RuntimeException('Invalid JSON response');
        }

        return $data;
    }

    /**
     * @param array<mixed>|string $data
     *
     * @return array<string,mixed>
     */
    public function normalizeApiResponse(
        array|string $data,
        int $cacheAge = 0,
        int $cacheTtl = 0,
        bool $fromCache = false,
        bool $staleCache = false,
        string $path = '',
    ): array {
        if (is_array($data)) {
            $nodeError = $this->nodeErrorFromPayload($data, $path, null);
            if ($nodeError !== null) {
                return $this->errorApiResponse($nodeError, $cacheAge, $cacheTtl, $fromCache, $staleCache);
            }
        }

        try {
            $normalized = match (true) {
                !is_array($data), $path === '' => $data,
                $path === 'apps' => $this->normalizeAppsData($data),
                $path === 'server/data' => $this->normalizeServerSystemData($data),
                $path === 'server/databases' => $this->normalizeDatabaseData($data),
                str_ends_with($path, '/sessions') => $this->normalizeAppSessions($data),
                str_ends_with($path, 'server/cronjobs') => $this->normalizeServerCronjobs($data),
                str_contains($path, '/logs/') || str_ends_with($path, '/logs') => $this->normalizeAppLogs($data),
                str_contains($path, '/readme') => $this->normalizeReadmeData($data),
                str_ends_with($path, 'composer-audit') => $this->normalizeComposerAuditData($data),
                str_contains($path, '/libraries') => $this->normalizeLibrariesData($data),
                $path === 'server/php' => $this->normalizePhpProcesses($data),
                default => $data,
            };
        } catch (InvalidNodePayloadException $e) {
            $this->logger->warning('Invalid node response payload', [
                'endpoint' => $e->endpoint,
                'message' => $e->getMessage(),
            ]);

            return $this->errorApiResponse($e->toErrorArray(), $cacheAge, $cacheTtl, $fromCache, $staleCache);
        }

        return $this->apiResponse($normalized, null, $cacheAge, $cacheTtl, $fromCache, $staleCache);
    }

    /**
     * Normalize a JSON node error body received from a non-2xx HTTP response.
     *
     * @return null|array{kind:string,message:string,endpoint:string,status_code:null|int}
     */
    public function normalizeNodeErrorResponse(string $body, string $endpoint, ?int $statusCode): ?array
    {
        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            return null;
        }

        return $this->nodeErrorFromPayload($decoded, $endpoint, $statusCode);
    }

    /**
     * @param array{kind:string,message:string,endpoint:string,status_code:null|int} $error
     *
     * @return array<string,mixed>
     */
    public function errorApiResponse(
        array $error,
        int $cacheAge = 0,
        int $cacheTtl = 0,
        bool $fromCache = false,
        bool $staleCache = false,
    ): array {
        return $this->apiResponse(null, $error, $cacheAge, $cacheTtl, $fromCache, $staleCache);
    }

    public function isValidJsonArray(string $json): bool
    {
        if ($json === '') {
            return false;
        }

        $decoded = json_decode($json, true);

        return json_last_error() === JSON_ERROR_NONE && is_array($decoded);
    }

    /**
     * @param array<string,mixed> $data
     *
     * @throws InvalidNodePayloadException When the node payload is invalid
     */
    public function normalizeAppsData(array $data): array
    {
        if (!$this->isSequentialArray($data) && $data !== []) {
            $normalized = [];
            foreach ($data as $appName => $appData) {
                if (!is_string($appName) || trim($appName) === '' || !is_array($appData)) {
                    throw new InvalidNodePayloadException('Node returned an invalid application entry.', 'apps');
                }

                $title = $this->nullableString($appData['title'] ?? null);
                $path = $this->nullableString($appData['path'] ?? null);
                $url = $this->nullableString($appData['url'] ?? null);
                $env = $this->nullableString($appData['env'] ?? null);
                $maintenance = TypeConverter::toBool($appData['maintenance'] ?? null);
                $status = $appData['status'] ?? null;
                $logs = $appData['logs'] ?? null;

                if ($title === null || $path === null || $url === null || !in_array($env, ['dev', 'test', 'stg', 'live'], true)
                    || $maintenance === null || !is_array($status) || !is_array($logs)) {
                    throw new InvalidNodePayloadException("Node returned an invalid application payload for {$appName}.", 'apps');
                }

                $fatal = TypeConverter::toInt($status['fatal'] ?? null);
                $warnings = TypeConverter::toInt($status['warnings'] ?? null);
                $total = TypeConverter::toInt($status['total'] ?? null);
                $normalizedLogs = $this->normalizeLogSizes($logs);
                if ($fatal === null || $warnings === null || $total === null || $fatal < 0 || $warnings < 0 || $total < 0 || $normalizedLogs === null) {
                    throw new InvalidNodePayloadException("Node returned invalid monitoring data for {$appName}.", 'apps');
                }

                $description = $this->nullableString($appData['description'] ?? null);
                if (array_key_exists('description', $appData) && $appData['description'] !== null && $description === null) {
                    throw new InvalidNodePayloadException("Node returned an invalid description for {$appName}.", 'apps');
                }

                $normalized[$appName] = array_merge($appData, [
                    'title' => $title,
                    'path' => $path,
                    'url' => $url,
                    'env' => $env,
                    'maintenance' => $maintenance,
                    'description' => $description,
                    'status' => ['fatal' => $fatal, 'warnings' => $warnings, 'total' => $total],
                    'logs' => $normalizedLogs,
                    'session' => $this->normalizeSessionData(
                        isset($appData['session']) && is_array($appData['session']) ? $appData['session'] : []
                    ),
                ]);
            }

            return $normalized;
        }

        if ($data === []) {
            return [];
        }

        throw new InvalidNodePayloadException('Node returned an applications list instead of an application map.', 'apps');
    }

    /** @param array<string,mixed> $session */
    public function normalizeSessionData(array $session): array
    {
        $type = $this->nullableString($session['type'] ?? null);
        $status = $session['status'] ?? null;
        $validStatuses = ['ok', 'unconfigured', 'error', 'unsupported'];
        $counts = [
            'count_last_hour' => $this->nullableInt($session['count_last_hour'] ?? null),
            'count_last_day' => $this->nullableInt($session['count_last_day'] ?? null),
            'count_total' => $this->nullableInt($session['count_total'] ?? null),
        ];
        $path = $this->nullableString($session['path'] ?? null);
        $sizeKb = $this->nullableFloat($session['size_kb'] ?? null);
        $error = $this->nullableString($session['error'] ?? null);

        if ($type === null || !is_string($status) || !in_array($status, $validStatuses, true)
            || ($session['count_last_hour'] ?? null) !== null && $counts['count_last_hour'] === null
            || ($session['count_last_day'] ?? null) !== null && $counts['count_last_day'] === null
            || ($session['count_total'] ?? null) !== null && $counts['count_total'] === null
            || ($session['path'] ?? null) !== null && $path === null
            || ($session['size_kb'] ?? null) !== null && $sizeKb === null
            || ($session['error'] ?? null) !== null && $error === null) {
            $this->logger->warning('Unrecognized session payload', ['data_keys' => array_keys($session)]);

            return [
                'type' => 'unknown', 'status' => 'error',
                'count_last_hour' => null, 'count_last_day' => null, 'count_total' => null,
                'path' => null, 'size_kb' => null, 'error' => 'unrecognized session payload',
            ];
        }

        return ['type' => $type, 'status' => $status] + $counts + [
            'path' => $path, 'size_kb' => $sizeKb, 'error' => $error,
        ];
    }

    /** @param array<string,mixed> $data */
    public function normalizeAppSessions(array $data): array
    {
        return $this->normalizeSessionData($data);
    }

    /**
     * @param array<mixed> $data
     *
     * @throws InvalidNodePayloadException When the node payload is invalid
     */
    public function normalizeServerCronjobs(array $data): array
    {
        if (!$this->isSequentialArray($data)) {
            throw new InvalidNodePayloadException('Node returned an invalid cronjob list.', 'server/cronjobs');
        }

        $normalized = [];
        foreach ($data as $entry) {
            if (!is_array($entry)) {
                throw new InvalidNodePayloadException('Node returned an invalid cronjob entry.', 'server/cronjobs');
            }

            $exists = TypeConverter::toBool($entry['exists'] ?? null);
            $stale = TypeConverter::toBool($entry['stale'] ?? null);
            $index = $this->nullableInt($entry['index'] ?? null);
            $path = $this->nullableString($entry['path'] ?? null);
            $mtime = $this->nullableString($entry['mtime'] ?? null);
            $age = $this->nullableInt($entry['age_seconds'] ?? null);
            $size = $this->nullableInt($entry['size'] ?? null);
            $tail = $entry['content_tail'] ?? [];

            if ($exists === null || $stale === null || ($entry['index'] ?? null) !== null && $index === null
                || ($entry['path'] ?? null) !== null && $path === null || ($entry['mtime'] ?? null) !== null && $mtime === null
                || ($entry['age_seconds'] ?? null) !== null && $age === null || ($entry['size'] ?? null) !== null && $size === null
                || !is_array($tail) || array_filter($tail, static fn (mixed $line): bool => !is_string($line)) !== []) {
                throw new InvalidNodePayloadException('Node returned invalid cronjob monitoring data.', 'server/cronjobs');
            }

            $normalized[] = [
                'index' => $index, 'path' => $path, 'exists' => $exists, 'mtime' => $mtime,
                'age_seconds' => $age, 'size' => $size, 'stale' => $stale, 'content_tail' => array_values($tail),
            ];
        }

        return $normalized;
    }

    /**
     * @param array<string,mixed> $data
     *
     * @throws InvalidNodePayloadException When the node payload is invalid
     */
    public function normalizeServerSystemData(array $data): array
    {
        $normalized = $data;
        foreach (['cpu_usage' => 'float', 'ram_capacity' => 'int', 'ram_used' => 'int', 'disk_space_capacity' => 'int', 'disk_space_used' => 'int', 'total_system_filecount' => 'int'] as $field => $type) {
            $value = $type === 'float' ? TypeConverter::toFloat($data[$field] ?? null) : TypeConverter::toInt($data[$field] ?? null);
            if ($value === null || $value < 0) {
                throw new InvalidNodePayloadException("Node returned invalid {$field} data.", 'server/data');
            }
            $normalized[$field] = $value;
        }

        $processes = $data['processes'] ?? null;
        if (!is_array($processes)) {
            throw new InvalidNodePayloadException('Node returned invalid process data.', 'server/data');
        }
        foreach (['total', 'php', 'sql'] as $field) {
            $value = TypeConverter::toInt($processes[$field] ?? null);
            if ($value === null || $value < 0) {
                throw new InvalidNodePayloadException("Node returned invalid process {$field} data.", 'server/data');
            }
            $normalized['processes'][$field] = $value;
        }

        $normalized['cronjobs'] = $this->normalizeCronjobSummary(
            isset($data['cronjobs']) && is_array($data['cronjobs']) ? $data['cronjobs'] : []
        );

        return $normalized;
    }

    /**
     * @param array<string,mixed> $data
     *
     * @throws InvalidNodePayloadException When the node payload is invalid
     */
    public function normalizeDatabaseData(array $data): array
    {
        $processes = $data['processes'] ?? null;
        $sql = is_array($processes) ? TypeConverter::toInt($processes['sql'] ?? null) : null;
        if ($sql === null || $sql < 0) {
            throw new InvalidNodePayloadException('Node returned invalid database process data.', 'server/databases');
        }

        $data['processes']['sql'] = $sql;

        return $data;
    }

    /** @param array<string,mixed> $data */
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

    /** @param array<string,mixed> $data */
    public function normalizeReadmeData(array $data): string
    {
        if (isset($data['content']) && is_string($data['content'])) {
            return $data['content'];
        }

        $this->logger->warning('README response did not contain markdown content', ['data_keys' => array_keys($data)]);

        return '';
    }

    /**
     * @param array<string,mixed> $data
     *
     * @throws InvalidNodePayloadException When the node payload is invalid
     */
    public function normalizeLibrariesData(array $data): array
    {
        if ($this->isSequentialArray($data) && $data !== []) {
            throw new InvalidNodePayloadException('Node returned a libraries list instead of an application map.', 'libraries');
        }

        $normalized = [];
        foreach ($data as $app => $libraries) {
            if (!is_string($app) || trim($app) === '' || !is_array($libraries) || !$this->isSequentialArray($libraries)) {
                throw new InvalidNodePayloadException('Node returned an invalid application libraries payload.', 'libraries');
            }
            foreach ($libraries as $library) {
                if (!is_array($library)) {
                    throw new InvalidNodePayloadException("Node returned an invalid library for {$app}.", 'libraries');
                }
                $name = $this->nullableString($library['name'] ?? null);
                $version = $this->nullableString($library['version'] ?? null);
                $description = $this->nullableString($library['description'] ?? null);
                $type = $this->nullableString($library['type'] ?? null);
                if ($name === null || $version === null || ($library['description'] ?? null) !== null && $description === null
                    || ($library['type'] ?? null) !== null && $type === null) {
                    throw new InvalidNodePayloadException("Node returned invalid library data for {$app}.", 'libraries');
                }
                $normalized[$app][] = ['name' => $name, 'version' => $version, 'description' => $description ?? '', 'type' => $type ?? ''];
            }
        }

        return $normalized;
    }

    /**
     * Normalize Composer audit data for one app or an app map.
     */
    public function normalizeComposerAuditData(mixed $data): array
    {
        if (!is_array($data)) {
            return $this->normalizeComposerAuditPayload([]);
        }

        if (is_string($data['status'] ?? null)) {
            return $this->normalizeComposerAuditPayload($data);
        }

        $normalized = [];
        foreach ($data as $app => $payload) {
            $normalized[$app] = $this->normalizeComposerAuditPayload($payload);
        }

        return $normalized;
    }

    /** @return array<string,mixed> */
    private function normalizeComposerAuditPayload(mixed $payload): array
    {
        $counts = array_fill_keys(Severities::ORDER, 0) + ['total' => 0];
        if (!is_array($payload) || ($payload['status'] ?? null) !== 'ok') {
            return [
                'status' => 'error',
                'message' => is_array($payload) ? $this->nullableString($payload['message'] ?? null) : null,
                'advisories' => [],
                'abandoned' => [],
                'counts' => $counts,
            ];
        }

        $audit = is_array($payload['audit'] ?? null) ? $payload['audit'] : [];
        $abandoned = is_array($audit['abandoned'] ?? null) ? $audit['abandoned'] : [];
        $advisories = [];
        $rawAdvisories = $audit['advisories'] ?? [];
        if (is_array($rawAdvisories)) {
            foreach ($rawAdvisories as $package => $packageAdvisories) {
                if (!is_string($package) || !is_array($packageAdvisories)) {
                    continue;
                }

                foreach ($packageAdvisories as $advisory) {
                    if (is_array($advisory)) {
                        $advisories[] = $this->normalizeAdvisory($package, $advisory);
                    }
                }
            }
        }

        foreach ($advisories as $advisory) {
            ++$counts[$advisory['severity']];
            ++$counts['total'];
        }

        return [
            'status' => 'ok',
            'message' => null,
            'advisories' => $advisories,
            'abandoned' => $abandoned,
            'counts' => $counts,
        ];
    }

    private function normalizeSeverity(mixed $value): string
    {
        $severity = strtolower($this->nullableString($value) ?? 'unknown');

        return in_array($severity, Severities::ORDER, true) ? $severity : 'unknown';
    }

    /** @param array<string,mixed> $advisory */
    private function normalizeAdvisory(string $package, array $advisory): array
    {
        return [
            'package' => $package,
            'advisory_id' => $this->nullableString($advisory['advisoryId'] ?? null),
            'cve' => $this->nullableString($advisory['cve'] ?? null),
            'title' => $this->nullableString($advisory['title'] ?? null),
            'link' => $this->nullableString($advisory['link'] ?? null),
            'affected_versions' => $this->nullableString($advisory['affectedVersions'] ?? null),
            'severity' => $this->normalizeSeverity($advisory['severity'] ?? null),
            'reported_at' => $this->nullableString($advisory['reportedAt'] ?? null),
        ];
    }

    /** @param array<string,mixed> $data */
    public function normalizePhpProcesses(array $data): array
    {
        if ($this->isSequentialArray($data)) {
            foreach ($data as $index => $process) {
                if (!is_array($process)) {
                    $this->logger->warning("Invalid process data at index {$index}");
                }
            }
        }

        return $data;
    }

    /** @param array<string,mixed> $logs */
    private function normalizeLogSizes(array $logs): ?array
    {
        $normalized = [];
        foreach ($logs as $path => $size) {
            if (!is_string($path) || $path === '' || ($size = TypeConverter::toInt($size)) === null || $size < 0) {
                return null;
            }
            $normalized[$path] = $size;
        }

        return $normalized;
    }

    /** @param array<string,mixed> $cronjobs */
    private function normalizeCronjobSummary(array $cronjobs): ?array
    {
        $normalized = [];
        foreach (['total', 'working', 'stale', 'missing'] as $field) {
            $value = TypeConverter::toInt($cronjobs[$field] ?? null);
            if ($value === null || $value < 0) {
                $this->logger->warning('Unrecognized cronjob summary payload', ['data_keys' => array_keys($cronjobs)]);

                return null;
            }
            $normalized[$field] = $value;
        }

        return $normalized;
    }

    /**
     * @param null|array{kind:string,message:string,endpoint:string,status_code:null|int} $error
     *
     * @return array<string,mixed>
     */
    private function apiResponse(
        mixed $result,
        ?array $error,
        int $cacheAge,
        int $cacheTtl,
        bool $fromCache,
        bool $staleCache,
    ): array {
        return [
            'result' => $result,
            'error' => $error,
            'cache_age' => $cacheAge,
            'cache_ttl' => $cacheTtl,
            'from_cache' => $fromCache,
            'stale_cache' => $staleCache,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     *
     * @return null|array{kind:string,message:string,endpoint:string,status_code:null|int}
     */
    private function nodeErrorFromPayload(array $payload, string $endpoint, ?int $statusCode): ?array
    {
        if (($payload['error'] ?? null) !== true || !is_string($payload['message'] ?? null)) {
            return null;
        }

        $message = trim($payload['message']);
        if ($message === '') {
            return null;
        }

        $this->logger->warning('Node returned an error payload', [
            'endpoint' => $endpoint,
            'status_code' => $statusCode,
            'message' => $message,
        ]);

        return [
            'kind' => 'node_response',
            'message' => $message,
            'endpoint' => $endpoint,
            'status_code' => $statusCode,
        ];
    }

    /** @param array<mixed> $array */
    private function isSequentialArray(array $array): bool
    {
        return $array === [] || array_keys($array) === range(0, count($array) - 1);
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : TypeConverter::toInt($value);
    }

    private function nullableFloat(mixed $value): ?float
    {
        return $value === null ? null : TypeConverter::toFloat($value);
    }

    private function nullableString(mixed $value): ?string
    {
        return $value === null ? null : TypeConverter::toString($value);
    }
}
