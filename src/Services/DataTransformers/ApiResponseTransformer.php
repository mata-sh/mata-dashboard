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

                $title = TypeConverter::toString($appData['title'] ?? null);
                $path = TypeConverter::toString($appData['path'] ?? null);
                $url = TypeConverter::toString($appData['url'] ?? null);
                $env = TypeConverter::toString($appData['env'] ?? null);
                $statusUrl = $this->normalizeOptionalStatusUrl($appData);
                $status = $appData['status'] ?? null;
                $logs = $appData['logs'] ?? null;

                if ($title === null || $path === null || $url === null || !in_array($env, ['dev', 'test', 'stg', 'live'], true)
                    || $statusUrl === false || !is_array($status) || !is_array($logs)) {
                    throw new InvalidNodePayloadException("Node returned an invalid application payload for {$appName}.", 'apps');
                }

                $fatal = TypeConverter::toInt($status['fatal'] ?? null);
                $warnings = TypeConverter::toInt($status['warnings'] ?? null);
                $total = TypeConverter::toInt($status['total'] ?? null);
                $normalizedLogs = $this->normalizeLogSizes($logs);
                if ($fatal === null || $warnings === null || $total === null || $fatal < 0 || $warnings < 0 || $total < 0 || $normalizedLogs === null) {
                    throw new InvalidNodePayloadException("Node returned invalid monitoring data for {$appName}.", 'apps');
                }

                $description = TypeConverter::toString($appData['description'] ?? null);
                if (array_key_exists('description', $appData) && $appData['description'] !== null && $description === null) {
                    throw new InvalidNodePayloadException("Node returned an invalid description for {$appName}.", 'apps');
                }

                $normalizedApp = $appData;
                unset($normalizedApp['maintenance']);
                $normalized[$appName] = array_merge($normalizedApp, [
                    'title' => $title,
                    'path' => $path,
                    'url' => $url,
                    'env' => $env,
                    'description' => $description,
                    'status' => ['fatal' => $fatal, 'warnings' => $warnings, 'total' => $total],
                    'logs' => $normalizedLogs,
                    'session' => $this->normalizeSessionData(
                        isset($appData['session']) && is_array($appData['session']) ? $appData['session'] : []
                    ),
                ]);
                if ($statusUrl !== null) {
                    $normalized[$appName]['status_url'] = $statusUrl;
                }
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
        $type = TypeConverter::toString($session['type'] ?? null);
        $status = $session['status'] ?? null;
        $validStatuses = ['ok', 'unconfigured', 'error', 'unsupported'];
        $counts = [
            'count_last_hour' => TypeConverter::toInt($session['count_last_hour'] ?? null),
            'count_last_day' => TypeConverter::toInt($session['count_last_day'] ?? null),
            'count_total' => TypeConverter::toInt($session['count_total'] ?? null),
        ];
        $path = TypeConverter::toString($session['path'] ?? null);
        $sizeKb = TypeConverter::toFloat($session['size_kb'] ?? null);
        $error = TypeConverter::toString($session['error'] ?? null);

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
            $index = TypeConverter::toInt($entry['index'] ?? null);
            $path = TypeConverter::toString($entry['path'] ?? null);
            $mtime = TypeConverter::toString($entry['mtime'] ?? null);
            $age = TypeConverter::toInt($entry['age_seconds'] ?? null);
            $size = TypeConverter::toInt($entry['size'] ?? null);
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

        if (array_key_exists('databases', $data)) {
            if ($data['databases'] === null) {
                unset($data['databases']);
            } elseif (!is_array($data['databases'])) {
                throw new InvalidNodePayloadException('Node returned an invalid databases map.', 'server/databases');
            } else {
                $databases = [];
                foreach ($data['databases'] as $databaseName => $entry) {
                    $databases[$databaseName] = $this->normalizeDatabaseEntry((string) $databaseName, $entry);
                }
                $data['databases'] = $databases;
            }
        }

        return $data;
    }

    /**
     * @return array{
     *     database:null|string,
     *     configured_database:null|string,
     *     host:null|string,
     *     port:null|int,
     *     description:null|string,
     *     max_connections:null|int,
     *     active_threads:null|int,
     *     active_processes:null|int,
     *     process_list:array<int,array<string,mixed>>,
     *     connection_error:string
     * }
     */
    private function normalizeDatabaseEntry(string $name, mixed $entry): array
    {
        if (!is_array($entry) || (array_key_exists('connection_error', $entry)
            && $entry['connection_error'] !== null && !is_scalar($entry['connection_error']))) {
            $this->logger->warning('Malformed database entry from node', [
                'endpoint' => 'server/databases',
                'database' => $name,
            ]);

            return [
                'database' => null,
                'configured_database' => null,
                'host' => null,
                'port' => null,
                'description' => null,
                'max_connections' => null,
                'active_threads' => null,
                'active_processes' => null,
                'process_list' => [],
                'connection_error' => 'Malformed entry from node',
            ];
        }

        $port = TypeConverter::toInt($entry['port'] ?? null);
        if ($port === null || $port < 1 || $port > 65535) {
            $port = null;
        }

        $counts = [];
        foreach (['max_connections', 'active_threads', 'active_processes'] as $field) {
            $count = TypeConverter::toInt($entry[$field] ?? null);
            $counts[$field] = $count === null || $count < 0 ? null : $count;
        }

        $processList = $entry['process_list'] ?? null;
        if (!is_array($processList)) {
            $processList = [];
        }

        return [
            'database' => TypeConverter::toString($entry['database'] ?? null),
            'configured_database' => TypeConverter::toString($entry['configured_database'] ?? null),
            'host' => TypeConverter::toString($entry['host'] ?? null),
            'port' => $port,
            'description' => TypeConverter::toString($entry['description'] ?? null),
            'max_connections' => $counts['max_connections'],
            'active_threads' => $counts['active_threads'],
            'active_processes' => $counts['active_processes'],
            'process_list' => array_values(array_filter($processList, static fn (mixed $row): bool => is_array($row))),
            'connection_error' => TypeConverter::toString($entry['connection_error'] ?? null) ?? '',
        ];
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
                $name = TypeConverter::toString($library['name'] ?? null);
                $version = TypeConverter::toString($library['version'] ?? null);
                $description = TypeConverter::toString($library['description'] ?? null);
                $type = TypeConverter::toString($library['type'] ?? null);
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
                'message' => is_array($payload) ? TypeConverter::toString($payload['message'] ?? null) : null,
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
        $severity = strtolower(TypeConverter::toString($value) ?? 'unknown');

        return in_array($severity, Severities::ORDER, true) ? $severity : 'unknown';
    }

    /** @param array<string,mixed> $advisory */
    private function normalizeAdvisory(string $package, array $advisory): array
    {
        return [
            'package' => $package,
            'advisory_id' => TypeConverter::toString($advisory['advisoryId'] ?? null),
            'cve' => TypeConverter::toString($advisory['cve'] ?? null),
            'title' => TypeConverter::toString($advisory['title'] ?? null),
            'link' => TypeConverter::toString($advisory['link'] ?? null),
            'affected_versions' => TypeConverter::toString($advisory['affectedVersions'] ?? null),
            'severity' => $this->normalizeSeverity($advisory['severity'] ?? null),
            'reported_at' => TypeConverter::toString($advisory['reportedAt'] ?? null),
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

    /**
     * @param array<string,mixed> $appData
     *
     * @return null|false|string false denotes an invalid configured value
     */
    private function normalizeOptionalStatusUrl(array $appData): false|string|null
    {
        if (!array_key_exists('status_url', $appData)) {
            return null;
        }

        $statusUrl = TypeConverter::toString($appData['status_url']);
        if ($statusUrl === null || !filter_var($statusUrl, FILTER_VALIDATE_URL)) {
            return false;
        }

        $parts = parse_url($statusUrl);
        if ($parts === false || !isset($parts['host']) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
            return false;
        }

        return $statusUrl;
    }

}
