<?php

declare(strict_types=1);

namespace Mata\Services\Collect;

use Mata\Services\Audit\Severity;
use Mata\Services\Monitor\Dto\AppMonitoringSettings;
use Mata\Utils\TypeConverter;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Parses API JSON and converts endpoint payloads into dashboard contracts.
 */
class NodeResponseNormalizer
{
    /**
     * Instance-wide status fields; `true` marks fields an engine may report as null.
     */
    private const DATABASE_STATUS_FIELDS = [
        'max_connections' => false,
        'connections' => false,
        'running' => false,
        'max_used_connections' => true,
        'refused_connections' => true,
        'aborted_connects' => true,
        'uptime_seconds' => false,
    ];

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
        $notice = null;
        if (is_array($data)) {
            $nodeError = $this->nodeErrorFromPayload($data, $path, null);
            if ($nodeError !== null) {
                return $this->errorApiResponse($nodeError, $cacheAge, $cacheTtl, $fromCache, $staleCache);
            }

            $notice = $this->normalizeNotice($data['notice'] ?? null, $path);
            unset($data['notice']);
        }

        try {
            $normalized = match (true) {
                !is_array($data), $path === '' => $data,
                $path === 'apps' => $this->normalizeAppsData($data),
                $path === 'server/data' => $this->normalizeServerSystemData($data),
                $path === 'databases/overview' => $this->normalizeDatabaseOverview($data),
                $path === 'databases/processes' => $this->normalizeDatabaseProcesses($data),
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
                'response' => $data,
            ]);

            return $this->errorApiResponse($e->toErrorArray(), $cacheAge, $cacheTtl, $fromCache, $staleCache);
        }

        return $this->apiResponse($normalized, null, $cacheAge, $cacheTtl, $fromCache, $staleCache, $notice);
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

    private function isValidJsonArray(string $json): bool
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
     * @return array<string,array<string,mixed>>
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
                $composerEnabled = array_key_exists('composer', $appData)
                    ? TypeConverter::toBool($appData['composer'])
                    : false;

                if ($title === null || $path === null || $url === null || !in_array($env, ['dev', 'test', 'stg', 'prod', 'live'], true)
                    || $statusUrl === false || $composerEnabled === null) {
                    throw new InvalidNodePayloadException("Node returned an invalid application payload for {$appName}.", 'apps');
                }

                $monitoring = $this->normalizeAppMonitoring($appData, $appName);
                $loganalysisEnabled = $monitoring->isEnabled('loganalysis');
                $logsizeEnabled = $monitoring->isEnabled('logsize');
                $sessionsEnabled = $monitoring->isEnabled('sessions');

                $normalizedStatus = $loganalysisEnabled
                    ? $this->normalizeAppStatus($appData['status'] ?? null, $appName)
                    : null;
                $normalizedLogs = $logsizeEnabled
                    ? $this->normalizeAppLogSizes($appData['logs'] ?? null, $appName)
                    : null;

                $description = TypeConverter::toString($appData['description'] ?? null);
                if (array_key_exists('description', $appData) && $appData['description'] !== null && $description === null
                    && (!is_string($appData['description']) || trim($appData['description']) !== '')) {
                    throw new InvalidNodePayloadException("Node returned an invalid description for {$appName}.", 'apps');
                }

                $normalizedApp = $appData;
                unset($normalizedApp['maintenance']);

                // A disabled check is omitted by the node and must stay absent after normalization.
                if (!$loganalysisEnabled) {
                    unset($normalizedApp['status']);
                }
                if (!$logsizeEnabled) {
                    unset($normalizedApp['logs']);
                }
                if (!$sessionsEnabled) {
                    unset($normalizedApp['session']);
                }

                $normalizedFields = [
                    'title' => $title,
                    'path' => $path,
                    'url' => $url,
                    'env' => $env === 'live' ? 'prod' : $env,
                    'description' => $description,
                    'composer' => $composerEnabled,
                ];
                if ($normalizedStatus !== null) {
                    $normalizedFields['status'] = $normalizedStatus;
                }
                if ($normalizedLogs !== null) {
                    $normalizedFields['logs'] = $normalizedLogs;
                }
                if ($sessionsEnabled) {
                    $normalizedFields['session'] = $this->normalizeSessionData(
                        isset($appData['session']) && is_array($appData['session']) ? $appData['session'] : []
                    );
                }

                $normalized[$appName] = array_merge($normalizedApp, $normalizedFields);
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

    /**
     * @param array<string,mixed> $appData
     *
     * @throws InvalidNodePayloadException When the monitoring block is malformed
     */
    private function normalizeAppMonitoring(array $appData, string $appName): AppMonitoringSettings
    {
        if (!array_key_exists('monitoring', $appData)) {
            return AppMonitoringSettings::allEnabled();
        }

        $monitoring = $appData['monitoring'];
        if (!is_array($monitoring)) {
            throw new InvalidNodePayloadException("Node returned an invalid monitoring block for {$appName}.", 'apps');
        }

        foreach ($monitoring as $setting => $value) {
            if (!is_string($setting) || !in_array($setting, AppMonitoringSettings::SETTINGS, true) || !is_bool($value)) {
                throw new InvalidNodePayloadException("Node returned an invalid monitoring block for {$appName}.", 'apps');
            }
        }

        return AppMonitoringSettings::fromAppPayload($appData, $this->logger);
    }

    /**
     * @return array{fatal:int,warnings:int,total:int,fatal_cursor:null|string}
     *
     * @throws InvalidNodePayloadException When the log-analysis status is malformed
     */
    private function normalizeAppStatus(mixed $status, string $appName): array
    {
        if (!is_array($status)) {
            throw new InvalidNodePayloadException("Node returned an invalid application payload for {$appName}.", 'apps');
        }

        $fatal = TypeConverter::toInt($status['fatal'] ?? null);
        $warnings = TypeConverter::toInt($status['warnings'] ?? null);
        $total = TypeConverter::toInt($status['total'] ?? null);
        $fatalCursor = $status['fatal_cursor'] ?? null;
        $validFatalCursor = $fatal === 0
            ? $fatalCursor === null
            : is_string($fatalCursor) && preg_match('/^v1:[a-f0-9]{64}$/', $fatalCursor) === 1;
        if ($fatal === null || $warnings === null || $total === null || $fatal < 0 || $warnings < 0 || $total < 0
            || !$validFatalCursor) {
            throw new InvalidNodePayloadException("Node returned invalid monitoring data for {$appName}.", 'apps');
        }

        return [
            'fatal' => $fatal,
            'warnings' => $warnings,
            'total' => $total,
            'fatal_cursor' => is_string($fatalCursor) ? $fatalCursor : null,
        ];
    }

    /**
     * @return array<string,int>
     *
     * @throws InvalidNodePayloadException When the log-size map is malformed
     */
    private function normalizeAppLogSizes(mixed $logs, string $appName): array
    {
        if (!is_array($logs)) {
            throw new InvalidNodePayloadException("Node returned an invalid application payload for {$appName}.", 'apps');
        }

        $normalized = $this->normalizeLogSizes($logs);
        if ($normalized === null) {
            throw new InvalidNodePayloadException("Node returned invalid monitoring data for {$appName}.", 'apps');
        }

        return $normalized;
    }

    /**
     * @param array<string,mixed> $session
     *
     * @return array<string,mixed>
     */
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

    /**
     * @param array<string,mixed> $data
     *
     * @return array<string,mixed>
     */
    public function normalizeAppSessions(array $data): array
    {
        return $this->normalizeSessionData($data);
    }

    /**
     * @param array<mixed> $data
     *
     * @return list<array<string,mixed>>
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
     * Normalize the `server/data` endpoint. RAM and disk values are reported and retained in KB.
     *
     * @param array<string,mixed> $data
     *
     * @return array<string,mixed>
     *
     * @throws InvalidNodePayloadException When the node payload is invalid
     */
    public function normalizeServerSystemData(array $data): array
    {
        $normalized = $data;
        $normalized['cpu_usage'] = $this->normalizeNonNegativeFloat($data['cpu_usage'] ?? null, 'cpu_usage');
        $normalized['ram_capacity'] = $this->normalizeNonNegativeInt($data['ram_capacity'] ?? null, 'ram_capacity');
        $normalized['ram_used'] = $this->normalizeNonNegativeInt($data['ram_used'] ?? null, 'ram_used');
        $normalized['disk_space_capacity'] = $this->normalizeNonNegativeInt(
            $data['disk_space_capacity'] ?? null,
            'disk_space_capacity'
        );
        $normalized['disk_space_used'] = $this->normalizeNonNegativeInt(
            $data['disk_space_used'] ?? null,
            'disk_space_used'
        );
        $normalized['total_system_filecount'] = $this->normalizeNonNegativeInt(
            $data['total_system_filecount'] ?? null,
            'total_system_filecount'
        );

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

    private function normalizeNonNegativeInt(mixed $value, string $field): int
    {
        $normalized = TypeConverter::toInt($value);
        if ($normalized === null || $normalized < 0) {
            throw new InvalidNodePayloadException("Node returned invalid {$field} data.", 'server/data');
        }

        return $normalized;
    }

    private function normalizeNonNegativeFloat(mixed $value, string $field): float
    {
        $normalized = TypeConverter::toFloat($value);
        if ($normalized === null || $normalized < 0) {
            throw new InvalidNodePayloadException("Node returned invalid {$field} data.", 'server/data');
        }

        return $normalized;
    }

    /**
     * @param array<string,mixed> $data
     *
     * @return array{
     *     connections:list<array<string,mixed>>
     * }
     *
     * @throws InvalidNodePayloadException When the node payload is invalid
     */
    public function normalizeDatabaseOverview(array $data): array
    {
        $entries = $data['connections'] ?? null;
        if (!is_array($entries) || !array_is_list($entries)) {
            throw new InvalidNodePayloadException('Node returned an invalid connections list.', 'databases/overview');
        }
        $connections = [];
        $names = [];
        foreach ($entries as $entry) {
            $name = is_array($entry) ? ($entry['connection'] ?? null) : null;
            if (!is_string($name) || trim($name) === '' || in_array($name, $names, true)) {
                throw new InvalidNodePayloadException('Node returned an invalid connection name.', 'databases/overview');
            }
            $names[] = $name;
            $connections[] = $this->normalizeDatabaseConnection($entry);
        }

        return ['connections' => $connections];
    }

    /**
     * @param array<string,mixed> $data
     *
     * @return array<string,mixed>
     */
    private function normalizeDatabaseConnection(array $data): array
    {
        $endpoint = 'databases/overview';
        $error = $data['error'] ?? null;
        if ($error !== null && (!is_array($error) || ($error['status_code'] ?? null) !== 503)) {
            throw new InvalidNodePayloadException('Node returned an invalid connection error.', $endpoint);
        }
        $engine = TypeConverter::toString($data['engine'] ?? null);
        $collectedAt = TypeConverter::toString($data['collected_at'] ?? null);
        $status = $data['status'] ?? null;
        $databases = $data['databases'] ?? null;
        if ($engine === null || $collectedAt === null || ($error === null ? !is_array($status) : $status !== null) || !is_array($databases) || !array_is_list($databases)) {
            throw new InvalidNodePayloadException('Node returned an invalid database overview.', $endpoint);
        }

        $normalizedStatus = [];
        foreach ($error === null ? self::DATABASE_STATUS_FIELDS : [] as $field => $nullable) {
            $value = $status[$field] ?? null;
            if (!(is_int($value) && $value >= 0) && !($nullable && $value === null)) {
                throw new InvalidNodePayloadException("Node returned invalid database {$field} data.", $endpoint);
            }
            $normalizedStatus[$field] = $value;
        }

        $normalizedDatabases = [];
        foreach ($databases as $entry) {
            $name = is_array($entry) ? TypeConverter::toString($entry['name'] ?? null) : null;
            if ($name === null || !is_string($entry['description'] ?? null)) {
                throw new InvalidNodePayloadException('Node returned an invalid monitored database entry.', $endpoint);
            }
            $normalizedDatabases[] = ['name' => $name, 'description' => $entry['description']];
        }

        return [
            'engine' => $engine,
            'collected_at' => $collectedAt,
            'connection' => $data['connection'],
            'status' => $error === null ? $normalizedStatus : null,
            'error' => $error === null ? null : ['status_code' => 503, 'message' => 'Database connection failed'],
            'databases' => $normalizedDatabases,
        ];
    }

    /**
     * @param array<string,mixed> $data
     *
     * @return array{
     *     visibility:string,
     *     summary_by_database:list<array{database:string,connections:int,running:int}>,
     *     processes:list<array<string,mixed>>,
     *     truncated:bool
     * }
     *
     * @throws InvalidNodePayloadException When the node payload is invalid
     */
    public function normalizeDatabaseProcesses(array $data): array
    {
        $endpoint = 'databases/processes';
        $connection = $data['connection'] ?? null;
        if (!is_string($connection) || trim($connection) === '') {
            throw new InvalidNodePayloadException('Node returned an invalid connection name.', $endpoint);
        }
        $engine = TypeConverter::toString($data['engine'] ?? null);
        $visibility = $data['visibility'] ?? null;
        $summary = $data['summary_by_database'] ?? null;
        $processes = $data['processes'] ?? null;
        if ($engine === null || !in_array($visibility, ['all_sessions', 'own_sessions'], true) || !is_bool($data['truncated'] ?? null)
            || !is_array($summary) || !array_is_list($summary) || !is_array($processes) || !array_is_list($processes)) {
            throw new InvalidNodePayloadException('Node returned an invalid database process list.', $endpoint);
        }

        $normalizedSummary = [];
        foreach ($summary as $group) {
            $database = is_array($group) ? TypeConverter::toString($group['database'] ?? null) : null;
            $connections = $group['connections'] ?? null;
            $running = $group['running'] ?? null;
            if ($database === null || !is_int($connections) || $connections < 0 || !is_int($running) || $running < 0) {
                throw new InvalidNodePayloadException('Node returned an invalid database process summary.', $endpoint);
            }
            $normalizedSummary[] = ['database' => $database, 'connections' => $connections, 'running' => $running];
        }

        $normalizedProcesses = [];
        foreach ($processes as $process) {
            if (!is_array($process)) {
                throw new InvalidNodePayloadException('Node returned an invalid database process row.', $endpoint);
            }
            $normalizedProcesses[] = $process;
        }

        return [
            'engine' => $engine,
            'connection' => $connection,
            'visibility' => $visibility,
            'summary_by_database' => $normalizedSummary,
            'processes' => $normalizedProcesses,
            'truncated' => $data['truncated'],
        ];
    }

    /**
     * @param array<string,mixed> $data
     *
     * @return array<string,mixed>
     *
     * @throws InvalidNodePayloadException When the node payload is invalid
     */
    public function normalizeAppLogs(array $data): array
    {
        $entries = $data['entries'] ?? null;
        $readoutLimit = TypeConverter::toInt($data['readout_limit'] ?? null);
        if (!is_array($entries) || $readoutLimit === null || $readoutLimit < 1) {
            throw new InvalidNodePayloadException('Node returned invalid application log metadata.', 'logs');
        }

        foreach ($entries as $filePath => $group) {
            if (!is_array($group)) {
                $this->logger->warning('App log group is not an array, dropping', [
                    'file' => $filePath,
                    'type' => gettype($group),
                ]);
                unset($entries[$filePath]);
            }
        }

        return [
            'entries' => $entries,
            'readout_limit' => $readoutLimit,
        ];
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
     * @return array<string,list<array<string,string>>>
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
     *
     * @return array<string,mixed>
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
        $counts = array_fill_keys(Severity::values(), 0) + ['total' => 0];
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

        return (Severity::tryFrom($severity) ?? Severity::Unknown)->value;
    }

    /**
     * @param array<string,mixed> $advisory
     *
     * @return array<string,null|string>
     */
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

    /**
     * @param array<string,mixed> $data
     *
     * @return array<string,mixed>
     */
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

    /**
     * @param array<string,mixed> $logs
     *
     * @return null|array<string,int>
     */
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

    /**
     * @param array<string,mixed> $cronjobs
     *
     * @return null|array<string,int>
     */
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
     * @param null|array{code:string,message:string}                                      $notice
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
        ?array $notice = null,
    ): array {
        $response = [
            'result' => $result,
            'error' => $error,
            'cache_age' => $cacheAge,
            'cache_ttl' => $cacheTtl,
            'from_cache' => $fromCache,
            'stale_cache' => $staleCache,
        ];

        if ($notice !== null) {
            $response['notice'] = $notice;
        }

        return $response;
    }

    /**
     * @return null|array{code:string,message:string}
     */
    private function normalizeNotice(mixed $notice, string $endpoint): ?array
    {
        if ($notice === null) {
            return null;
        }

        if (!is_array($notice) || !is_string($notice['code'] ?? null) || !is_string($notice['message'] ?? null)) {
            $this->logger->warning('Node returned an invalid notice payload', ['endpoint' => $endpoint]);

            return null;
        }

        $code = trim($notice['code']);
        $message = trim($notice['message']);
        if ($code === '' || $message === '') {
            $this->logger->warning('Node returned an invalid notice payload', ['endpoint' => $endpoint]);

            return null;
        }

        return ['code' => $code, 'message' => $message];
    }

    /**
     * @param array<string,mixed> $payload
     *
     * @return null|array{kind:string,message:string,endpoint:string,status_code:null|int}
     */
    private function nodeErrorFromPayload(array $payload, string $endpoint, ?int $statusCode): ?array
    {
        $error = $payload['error'] ?? null;
        // Non-2xx bodies carry an error label (`{"error": "Unknown database", "message": ...}`).
        $isErrorBody = $error === true || $error === 'Monitoring disabled' || ($statusCode !== null && is_string($error));
        if (!$isErrorBody) {
            return null;
        }

        $nodeError = is_string($error) ? trim($error) : '';
        $nodeMessage = is_string($payload['message'] ?? null) ? trim($payload['message']) : '';
        if ($nodeMessage === '' && $nodeError === '') {
            return null;
        }

        $this->logger->warning('Node returned an error payload', [
            'endpoint' => $endpoint,
            'status_code' => $statusCode,
            'node_error' => $nodeError,
            'node_message' => $nodeMessage,
        ]);

        return [
            'kind' => 'node_response',
            'message' => $this->safeNodeErrorMessage($nodeError, $nodeMessage, $statusCode),
            'endpoint' => $endpoint,
            'status_code' => $statusCode,
        ];
    }

    /**
     * Pick the user-facing text for a node error.
     *
     * 5xx messages can carry hosts, driver errors or shell commands, so only the node's fixed
     * label is shown; the full message is logged. 403 and 429 bodies have no message at all.
     */
    private function safeNodeErrorMessage(string $nodeError, string $nodeMessage, ?int $statusCode): string
    {
        return match (true) {
            $nodeError === 'Service unavailable' => 'Node is in maintenance mode',
            $nodeError === 'Rate limit exceeded' => 'Node rate limit exceeded, try again later',
            $nodeError === 'Forbidden' => 'Node rejected this dashboard (IP not allowed)',
            $nodeError !== '' && ($nodeMessage === '' || ($statusCode ?? 0) >= 500) => $nodeError,
            default => $nodeMessage,
        };
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

        if ($appData['status_url'] === null) {
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
