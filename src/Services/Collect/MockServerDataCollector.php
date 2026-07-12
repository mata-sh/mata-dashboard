<?php

declare(strict_types=1);

namespace Mata\Services\Collect;

use LogicException;
use Mata\Constants\CacheTypes;
use Mata\Services\Cache\CacheTtlResolver;
use Mata\Services\DataTransformers\ApiResponseTransformer;
use Psr\Log\LoggerInterface;

/**
 * Mock server data collector for demo mode.
 *
 * Provides realistic demo data simulating server monitoring:
 * - Generates dynamic server metrics with variation
 * - Simulates multiple applications per server
 * - Provides realistic process, database, and library data
 * - Maintains cache compatibility for consistent demo experience
 */
class MockServerDataCollector implements ServerDataCollectorInterface
{
    private LoggerInterface $logger;
    private CacheTtlResolver $ttlResolver;
    private ApiResponseTransformer $dataTransformer;
    private string $serverName;
    private DemoAlarmMetricScenario $alarmMetricScenario;

    /** @var array<string,mixed> */
    private array $serverConfig;
    private int $cacheAge = 0;
    private ?string $projectReadme = null;

    /**
     * Initialize mock server data collector.
     *
     * @param LoggerInterface              $logger              PSR-3 logger for demo logging
     * @param CacheTtlResolver             $ttlResolver         Cache TTL configuration lookup
     * @param ApiResponseTransformer       $dataTransformer     Data transformer for response normalization
     * @param string                       $serverName          Server identifier for demo data generation
     * @param array<string,mixed>          $serverConfig        Server configuration from demo-servers.json
     * @param null|DemoAlarmMetricScenario $alarmMetricScenario Shared dashboard alarm scenario
     */
    public function __construct(
        LoggerInterface $logger,
        CacheTtlResolver $ttlResolver,
        ApiResponseTransformer $dataTransformer,
        string $serverName,
        array $serverConfig,
        ?DemoAlarmMetricScenario $alarmMetricScenario = null,
    ) {
        $this->logger = $logger;
        $this->ttlResolver = $ttlResolver;
        $this->dataTransformer = $dataTransformer;
        $this->serverName = $serverName;
        $this->serverConfig = $serverConfig;
        $this->alarmMetricScenario = $alarmMetricScenario ?? new DemoAlarmMetricScenario([$serverName]);
        $this->cacheAge = mt_rand(10, 180); // Simulate cache age
    }

    /**
     * Generate realistic server system metrics.
     */
    public function getServerSystemData(string $cacheType = 'serverData'): ?array
    {
        $ttlSeconds = $this->ttlResolver->get($cacheType);

        // Generate realistic metrics based on server type
        $baseMetrics = $this->getBaseMetricsForServerType($this->serverName);

        $data = [
            'cpu_usage' => $this->serverName === 'production-web'
                ? 90
                : $baseMetrics['cpu'] + mt_rand(-5, 15),
            'ram_capacity' => $baseMetrics['ram_capacity'],
            'ram_used' => $baseMetrics['ram_used'] + mt_rand(-200, 500),
            'disk_space_capacity' => $baseMetrics['disk_capacity'],
            'disk_space_used' => $baseMetrics['disk_used'] + mt_rand(-1000, 2000),
            'total_system_filecount' => mt_rand(180000, 350000),
            'uptime' => mt_rand(86400 * 5, 86400 * 45), // 5-45 days uptime
            'load_average' => round(($baseMetrics['cpu'] / 100) * 4 + mt_rand(-50, 50) / 100, 2),
            'network_rx_bytes' => mt_rand(1000000000, 50000000000),
            'network_tx_bytes' => mt_rand(800000000, 40000000000),
            'processes' => [
                'total' => mt_rand(80, 240),
                'php' => mt_rand(10, 40),
                'sql' => mt_rand(12, 20),
            ],
            'cronjobs' => ['total' => 5, 'working' => 5, 'stale' => 0, 'missing' => 0],
        ];

        $this->applyAlarmMetricScenario($data);

        return $this->dataTransformer->normalizeApiResponse($data, $this->cacheAge, $ttlSeconds, false, false, 'server/data');
    }

    /**
     * Add the selected dashboard alarm metric.
     *
     * The scenario is shared by all demo collectors, so one metric across the
     * dashboard reaches alarm level on each refresh.
     *
     * @param array<string,mixed> $data
     */
    private function applyAlarmMetricScenario(array &$data): void
    {
        $metric = $this->alarmMetricScenario->metricFor($this->serverName);
        if ($metric === null) {
            return;
        }

        match ($metric) {
            'cpu' => $data['cpu_usage'] = 97,
            'ram' => $data['ram_used'] = (int) round($data['ram_capacity'] * 0.97),
            'disk' => $data['disk_space_used'] = (int) round($data['disk_space_capacity'] * 0.95),
            'filecount' => $data['total_system_filecount'] = 950000,
            'cronjobs' => $data['cronjobs'] = ['total' => 5, 'working' => 0, 'stale' => 5, 'missing' => 0],
            default => throw new LogicException("Unsupported demo alarm metric: {$metric}"),
        };
    }

    /**
     * Generate demo server information.
     */
    public function getServerInfo(string $cacheType = 'serverInfo'): ?array
    {
        $ttlSeconds = $this->ttlResolver->get($cacheType);

        $osOptions = [
            'Ubuntu 22.04.3 LTS',
            'Ubuntu 20.04.6 LTS',
            'Debian GNU/Linux 11 (bullseye)',
            'Debian GNU/Linux 12 (bookworm)',
            'CentOS Linux 8.5.2111',
            'Rocky Linux 9.2',
            'AlmaLinux 9.1',
            'Fedora 38',
        ];

        $commonPhpExtensions = [
            'Core', 'date', 'libxml', 'openssl', 'pcre', 'zlib', 'filter', 'hash',
            'json', 'pcntl', 'readline', 'Reflection', 'SPL', 'session', 'standard',
            'mysqlnd', 'PDO', 'pdo_mysql', 'mysqli', 'curl', 'mbstring', 'gd',
            'xml', 'xmlreader', 'xmlwriter', 'SimpleXML', 'dom', 'fileinfo',
            'iconv', 'tokenizer', 'zip', 'Phar', 'ctype', 'posix', 'sockets',
        ];

        $optionalExtensions = [
            'redis', 'memcached', 'opcache', 'apcu', 'imagick', 'bcmath',
            'gmp', 'intl', 'soap', 'xsl', 'exif', 'calendar', 'ftp',
            'gettext', 'shmop', 'sysvmsg', 'sysvsem', 'sysvshm',
        ];

        // Randomly include some optional extensions
        $extensionCount = mt_rand(5, count($optionalExtensions) - 2);

        /** @var array<int, string> $selectedOptional */
        $selectedOptional = array_rand(array_flip($optionalExtensions), $extensionCount);

        $allExtensions = array_merge($commonPhpExtensions, $selectedOptional);
        sort($allExtensions);

        $data = [
            'os_name' => $osOptions[array_rand($osOptions)],
            'os_version' => $osOptions[array_rand($osOptions)],
            'kernel_version' => '5.' . mt_rand(10, 19) . '.' . mt_rand(0, 120) . '-' . mt_rand(1, 100) . '-generic',
            'package_count' => mt_rand(800, 2500),
            'php_version' => '8.' . mt_rand(1, 3) . '.' . mt_rand(0, 20),
            'php_extensions' => $allExtensions,
            'php_extension_count' => count($allExtensions),
            'architecture' => 'x86_64',
            'hostname' => $this->serverName . '.example.com',
        ];

        return $this->dataTransformer->normalizeApiResponse($data, $this->cacheAge, $ttlSeconds, false);
    }

    /**
     * Generate demo application data.
     */
    public function getApps(string $cacheType = 'apps'): ?array
    {
        $ttlSeconds = $this->ttlResolver->get($cacheType);
        $data = $this->generateDemoApps();

        return $this->dataTransformer->normalizeApiResponse(
            $data,
            $this->cacheAge,
            $ttlSeconds,
            false,
            false,
            'apps'
        );
    }

    /**
     * Generate demo PHP processes.
     */
    public function getServerPhpProcesses(string $cacheType = 'serverProcesses'): ?array
    {
        $ttlSeconds = $this->ttlResolver->get($cacheType);

        $processes = [];
        $processCount = mt_rand(15, 40);

        for ($i = 0; $i < $processCount; ++$i) {
            $processes[] = [
                'user' => ['www-data', 'nginx', 'apache', 'php-fpm'][array_rand(['www-data', 'nginx', 'apache', 'php-fpm'])],
                'pid' => mt_rand(1000, 65000),
                'ppid' => mt_rand(1, 1000),
                'cpu' => round(mt_rand(0, 1500) / 100, 1),
                'mem' => round(mt_rand(50, 2000) / 100, 1),
                'etime' => sprintf('%02d:%02d:%02d', mt_rand(0, 23), mt_rand(0, 59), mt_rand(0, 59)),
                'args' => 'php-fpm: pool ' . ['www', 'api', 'admin'][array_rand(['www', 'api', 'admin'])],
            ];
        }

        return $this->dataTransformer->normalizeApiResponse($processes, $this->cacheAge, $ttlSeconds, false);
    }

    /**
     * Generate demo database information.
     */
    public function getServerDatabases(string $cacheType = 'databases'): array|string|null
    {
        $ttlSeconds = $this->ttlResolver->get($cacheType);

        $data = [
            'mysql_version' => '8.0.' . mt_rand(25, 35),
            'total_databases' => mt_rand(8, 25),
            'total_tables' => mt_rand(150, 500),
            'data_size_mb' => mt_rand(500, 5000),
            'index_size_mb' => mt_rand(100, 1200),
            'connections_active' => mt_rand(5, 45),
            'connections_max' => 150,
            'uptime_hours' => mt_rand(120, 1080),
            'queries_per_second' => mt_rand(10, 200),
            'processes' => ['sql' => mt_rand(12, 20)],
            'databases' => [
                'mata_dashboard' => [
                    'database' => 'mata_dashboard',
                    'configured_database' => 'mata_dashboard',
                    'host' => 'localhost',
                    'port' => 3306,
                    'description' => 'Dashboard state store',
                    'max_connections' => 150,
                    'active_threads' => mt_rand(2, 10),
                    'active_processes' => mt_rand(1, 8),
                    'process_list' => [
                        ['id' => 101, 'user' => 'dashboard', 'host' => 'localhost', 'db' => 'mata_dashboard', 'command' => 'Query', 'time' => 0, 'state' => 'executing', 'info' => 'SELECT'],
                    ],
                    'connection_error' => '',
                ],
                'ecommerce_prod' => [
                    'database' => 'ecommerce_prod',
                    'configured_database' => 'ecommerce_prod',
                    'host' => 'db-prod.internal',
                    'port' => 3306,
                    'description' => 'Production ecommerce database',
                    'max_connections' => 250,
                    'active_threads' => mt_rand(5, 20),
                    'active_processes' => mt_rand(3, 15),
                    'process_list' => [
                        ['id' => 201, 'user' => 'storefront', 'host' => 'web-01', 'db' => 'ecommerce_prod', 'command' => 'Sleep', 'time' => 4, 'state' => '', 'info' => null],
                        ['id' => 202, 'user' => 'worker', 'host' => 'queue-01', 'db' => 'ecommerce_prod', 'command' => 'Query', 'time' => 1, 'state' => 'executing', 'info' => 'UPDATE'],
                    ],
                    'connection_error' => '',
                ],
                'analytics' => [
                    'database' => 'analytics',
                    'configured_database' => 'analytics',
                    'host' => 'analytics-db.internal',
                    'port' => 3307,
                    'description' => 'Reporting and analytics warehouse',
                    'max_connections' => 100,
                    'active_threads' => mt_rand(1, 6),
                    'active_processes' => mt_rand(1, 5),
                    'process_list' => [
                        ['id' => 301, 'user' => 'reporter', 'host' => 'reports-01', 'db' => 'analytics', 'command' => 'Query', 'time' => 12, 'state' => 'Sending data', 'info' => 'SELECT'],
                    ],
                    'connection_error' => '',
                ],
                'legacy_reports' => [
                    'database' => 'legacy_reports',
                    'configured_database' => 'legacy_reports',
                    'host' => 'legacy-db.internal',
                    'port' => 3306,
                    'description' => 'Legacy reporting database',
                    'max_connections' => 50,
                    'active_threads' => 0,
                    'active_processes' => 0,
                    'process_list' => [],
                    'connection_error' => 'SQLSTATE[HY000] [2002] Connection refused',
                ],
            ],
        ];

        return $this->dataTransformer->normalizeApiResponse($data, $this->cacheAge, $ttlSeconds, false, false, 'server/databases');
    }

    /**
     * Generate demo libraries for all apps.
     */
    public function getAppsLibraries(string $cacheType = 'apps'): ?array
    {
        $ttlSeconds = $this->ttlResolver->get($cacheType);

        $apps = array_keys($this->generateDemoApps());
        $libraries = [];

        foreach ($apps as $app) {
            $libraries[$app] = $this->generateLibrariesForApp($app);
        }

        return $this->dataTransformer->normalizeApiResponse($libraries, $this->cacheAge, $ttlSeconds, false, false, 'apps/libraries');
    }

    /**
     * Generate demo libraries for specific app.
     */
    public function getAppLibraries(string $app, string $cacheType = 'apps'): ?array
    {
        $ttlSeconds = $this->ttlResolver->get($cacheType);
        $data = $this->generateLibrariesForApp($app);

        return $this->dataTransformer->normalizeApiResponse($data, $this->cacheAge, $ttlSeconds, false, false, "{$app}/libraries");
    }

    /**
     * Generate demo README content.
     */
    public function fetchAppReadme(string $app, string $cacheType = 'readme'): ?array
    {
        $ttlSeconds = $this->ttlResolver->get($cacheType);

        $readmeContent = $this->generateReadmeContent($app);

        return $this->dataTransformer->normalizeApiResponse($readmeContent, $this->cacheAge, $ttlSeconds, false);
    }

    /**
     * Generate demo grouped log content matching new API structure.
     */
    public function fetchAppLogs(string $app, string $cacheType = 'appLogs'): ?array
    {
        $ttlSeconds = $this->ttlResolver->get($cacheType);

        $logFiles = [
            "{$app}/logs/error.log",
            "{$app}/logs/access.log",
            "{$app}/logs/debug.log",
        ];

        $groupedLogs = [];

        $errorTypes = [
            ['message' => 'PHP Fatal error: Uncaught Error: Call to undefined function', 'type' => 'Error'],
            ['message' => 'PHP Warning: Division by zero in', 'type' => 'Warning'],
            ['message' => 'PHP Notice: Undefined variable: data in', 'type' => 'Deprecated'],
            ['message' => 'MySQL server has gone away', 'type' => 'Error'],
            ['message' => 'Connection timed out after 30000ms', 'type' => 'Error'],
            ['message' => 'Failed to connect to database: Access denied', 'type' => 'Error'],
            ['message' => 'HTTP 500 Internal Server Error', 'type' => 'Error'],
            ['message' => 'Class \'App\Model\User\' not found', 'type' => 'Error'],
            ['message' => 'Session started', 'type' => 'Other'],
            ['message' => 'Request completed in 234ms', 'type' => 'Other'],
        ];

        foreach ($logFiles as $logFile) {
            $entriesCount = mt_rand(5, 20);
            $entries = [];

            for ($i = 0; $i < $entriesCount; ++$i) {
                $timestamp = date('Y-m-d H:i:s', time() - mt_rand(3600, 86400 * 7));
                $errorType = $errorTypes[array_rand($errorTypes)];
                $file = '/var/www/' . $app . '/src/' . ['Controller', 'Model', 'Service'][array_rand(['Controller', 'Model', 'Service'])] . '/'
                       . ucfirst($app) . ['Controller', 'Model', 'Service'][array_rand(['Controller', 'Model', 'Service'])] . '.php';
                $line = mt_rand(15, 250);

                $entries[] = [
                    'timestamp' => $timestamp,
                    'type' => $errorType['type'],
                    'message' => $errorType['message'] . ' ' . $file . ':' . $line,
                ];
            }

            $groupedLogs[$logFile] = $entries;
        }

        return $this->dataTransformer->normalizeApiResponse($groupedLogs, $this->cacheAge, $ttlSeconds, false);
    }

    /**
     * Generate demo log file metadata.
     */
    public function fetchAppLogsStatus(string $app, string $cacheType = 'appLogs'): ?array
    {
        $ttlSeconds = $this->ttlResolver->get($cacheType);

        $logsStatus = [
            "{$app}/logs/error.log" => [
                'size' => mt_rand(10240, 524288), // 10KB to 512KB
                'lines' => mt_rand(100, 5000),
                'last_modified' => date('Y-m-d H:i:s', time() - mt_rand(60, 86400)),
            ],
            "{$app}/logs/access.log" => [
                'size' => mt_rand(51200, 1048576), // 50KB to 1MB
                'lines' => mt_rand(500, 10000),
                'last_modified' => date('Y-m-d H:i:s', time() - mt_rand(60, 3600)),
            ],
            "{$app}/logs/debug.log" => [
                'size' => mt_rand(5120, 204800), // 5KB to 200KB
                'lines' => mt_rand(50, 2000),
                'last_modified' => date('Y-m-d H:i:s', time() - mt_rand(300, 43200)),
            ],
        ];

        return $this->dataTransformer->normalizeApiResponse($logsStatus, $this->cacheAge, $ttlSeconds, false);
    }

    /**
     * Fetch session status for a specific app.
     *
     * Returns the nested session object: `type`, `status`, `count_last_hour`,
     * `count_last_day`, `count_total`, `path`, `size_kb`, and `error` when
     * status is error/unsupported.
     *
     * @param string $app       App identifier
     * @param string $cacheType Cache type key for TTL lookup (default: 'sessions')
     *
     * @return null|array<string,mixed> Session data or null on failure
     */
    public function fetchAppSessions(string $app, string $cacheType = 'sessions'): ?array
    {
        $ttlSeconds = $this->ttlResolver->get($cacheType);

        $sessionVariants = [
            [
                'type' => 'file',
                'status' => 'ok',
                'count_last_hour' => 42,
                'count_last_day' => 512,
                'count_total' => 8214,
                'path' => '/var/lib/php/sessions',
                'size_kb' => 1843.2,
            ],
            [
                'type' => 'redis',
                'status' => 'ok',
                'count_last_hour' => null,
                'count_last_day' => null,
                'count_total' => 8214,
                'path' => null,
                'size_kb' => null,
            ],
            [
                'type' => 'none',
                'status' => 'unconfigured',
                'count_last_hour' => null,
                'count_last_day' => null,
                'count_total' => null,
                'path' => null,
                'size_kb' => null,
            ],
            [
                'type' => 'memcached',
                'status' => 'unsupported',
                'count_last_hour' => null,
                'count_last_day' => null,
                'count_total' => null,
                'path' => null,
                'size_kb' => null,
                'error' => 'session handler memcached is not supported',
            ],
        ];

        $sessions = $sessionVariants[abs((int) crc32($app)) % count($sessionVariants)];

        return $this->dataTransformer->normalizeApiResponse($sessions, $this->cacheAge, $ttlSeconds, false, path: "{$app}/sessions");
    }

    /**
     * Generate demo Composer audit results for a specific app.
     *
     * @return null|array<string,mixed> Normalized Composer audit response
     */
    public function fetchAppComposerAudit(string $app, string $cacheType = CacheTypes::AUDIT): ?array
    {
        $ttlSeconds = $this->ttlResolver->get($cacheType);

        return $this->dataTransformer->normalizeApiResponse(
            $this->generateComposerAuditForApp($app),
            $this->cacheAge,
            $ttlSeconds,
            false,
            false,
            "{$app}/composer-audit",
        );
    }

    /**
     * Return demo Composer audit results with a simulated older cache age.
     *
     * @return null|array<string,mixed> Normalized cached Composer audit response
     */
    public function getCachedAppComposerAudit(string $app): ?array
    {
        $ttlSeconds = $this->ttlResolver->get(CacheTypes::AUDIT);
        $cacheAge = 14400 + (abs((int) crc32($this->serverName . $app)) % 14400);

        return $this->dataTransformer->normalizeApiResponse(
            $this->generateComposerAuditForApp($app),
            $cacheAge,
            $ttlSeconds,
            true,
            false,
            "{$app}/composer-audit",
        );
    }

    /**
     * Fetch server cronjob heartbeat entries.
     *
     * Ordered list of entries with `index`, `path`, `exists`, `mtime`,
     * `age_seconds`, `size`, `stale`, `content_tail`.
     *
     * @param string $cacheType Cache type key for TTL lookup (default: 'cronjobs')
     *
     * @return null|array<string,mixed> Normalized response with cronjob entries or null on failure
     */
    public function fetchServerCronjobs(string $cacheType = 'cronjobs'): ?array
    {
        $ttlSeconds = $this->ttlResolver->get($cacheType);

        $cronjobs = [
            [
                'index' => 0,
                'path' => '/var/log/mata/cron/daily-report.log',
                'exists' => true,
                'mtime' => date('Y-m-d H:i:s', time() - 300),
                'age_seconds' => 300,
                'stale' => false,
                'size' => 2048,
                'content_tail' => [
                    '[2026-07-10 03:00:01] daily-report started',
                    '[2026-07-10 03:00:05] daily-report finished in 4.2s',
                ],
            ],
            [
                'index' => 1,
                'path' => '/var/log/mata/cron/cache-warmup.log',
                'exists' => true,
                'mtime' => date('Y-m-d H:i:s', time() - 93600),
                'age_seconds' => 93600,
                'stale' => true,
                'size' => 512,
                'content_tail' => [
                    '[2026-07-09 01:00:01] cache warmup started',
                    '[2026-07-09 01:00:02] cache warmup skipped: lock present',
                ],
            ],
        ];

        return $this->dataTransformer->normalizeApiResponse($cronjobs, $this->cacheAge, $ttlSeconds, false, path: 'server/cronjobs');
    }

    /**
     * Mock JSON validation.
     */
    public function isValidJsonArray(string $json): bool
    {
        $decoded = json_decode($json, true);

        return json_last_error() === JSON_ERROR_NONE && is_array($decoded);
    }

    /**
     * Generate base metrics based on server type.
     *
     * @return array<string,int>
     */
    private function getBaseMetricsForServerType(string $serverName): array
    {
        $metrics = [
            'production-web' => [
                'cpu' => 45,
                'ram_capacity' => 16384,
                'ram_used' => 11000,
                'disk_capacity' => 500000,
                'disk_used' => 280000,
            ],
            'database-server' => [
                'cpu' => 30,
                'ram_capacity' => 32768,
                'ram_used' => 24000,
                'disk_capacity' => 2000000,
                'disk_used' => 1200000,
            ],
            'staging-server' => [
                'cpu' => 15,
                'ram_capacity' => 8192,
                'ram_used' => 4500,
                'disk_capacity' => 200000,
                'disk_used' => 85000,
            ],
            'development-server' => [
                'cpu' => 25,
                'ram_capacity' => 8192,
                'ram_used' => 5200,
                'disk_capacity' => 250000,
                'disk_used' => 125000,
            ],
            'backup-server' => [
                'cpu' => 10,
                'ram_capacity' => 16384,
                'ram_used' => 6000,
                'disk_capacity' => 5000000,
                'disk_used' => 3500000,
            ],
            'load-balancer' => [
                'cpu' => 20,
                'ram_capacity' => 4096,
                'ram_used' => 2800,
                'disk_capacity' => 100000,
                'disk_used' => 35000,
            ],
        ];

        return $metrics[$serverName] ?? $metrics['production-web'];
    }

    /**
     * Generate demo applications for this server.
     *
     * @return array<string,mixed>
     */
    private function generateDemoApps(): array
    {
        /** @var array<string,array<string,mixed>> $appTemplates */
        $appTemplates = [
            'ecommerce' => [
                'title' => 'E-Commerce Platform',
                'description' => 'Customer-facing commerce and checkout platform.',
                'path' => 'ecommerce',
                'environment' => 'live',
                'category' => 'commerce',
                'suitable_environments' => ['live', 'stg'],
                'log_templates' => ['logs/error.log', 'logs/access.log', 'logs/payment.log', 'logs/queue.log'],
            ],
            'admin-panel' => [
                'title' => 'Admin Dashboard',
                'description' => 'Internal dashboard for administration and operations.',
                'path' => 'admin',
                'environment' => 'live',
                'category' => 'internal',
                'suitable_environments' => ['live', 'stg'],
                'log_templates' => ['logs/error.log', 'logs/access.log', 'logs/audit.log'],
            ],
            'api-gateway' => [
                'title' => 'API Gateway',
                'description' => 'Routes and authenticates requests for backend services.',
                'path' => 'api',
                'environment' => 'live',
                'category' => 'api',
                'suitable_environments' => ['live', 'test', 'stg'],
                'log_templates' => ['logs/error.log', 'logs/access.log', 'logs/request.log'],
            ],
            'user-portal' => [
                'title' => 'Customer Portal',
                'description' => 'Self-service portal for customers and account management.',
                'path' => 'portal',
                'environment' => 'test',
                'category' => 'customer',
                'suitable_environments' => ['live', 'test', 'stg'],
                'log_templates' => ['logs/error.log', 'logs/access.log', 'logs/ui.log'],
            ],
            'inventory-system' => [
                'title' => 'Inventory Management',
                'description' => 'Tracks inventory, stock levels, and fulfillment operations.',
                'path' => 'inventory',
                'environment' => 'live',
                'category' => 'operations',
                'suitable_environments' => ['live', 'stg'],
                'log_templates' => ['logs/error.log', 'logs/sync.log', 'logs/report.log'],
            ],
            'analytics-dashboard' => [
                'title' => 'Analytics Dashboard',
                'description' => 'Explores product and operational analytics.',
                'path' => 'analytics',
                'environment' => 'dev',
                'category' => 'analytics',
                'suitable_environments' => ['live', 'test', 'stg'],
                'log_templates' => ['logs/error.log', 'logs/query.log', 'logs/etl.log'],
            ],
            'payment-processor' => [
                'title' => 'Payment Gateway',
                'description' => 'Processes payments and settlement workflows.',
                'path' => 'payments',
                'environment' => 'live',
                'category' => 'commerce',
                'suitable_environments' => ['live'],
                'log_templates' => ['logs/error.log', 'logs/access.log', 'logs/security.log', 'logs/settlement.log'],
            ],
            'notification-service' => [
                'title' => 'Notification Service',
                'description' => 'Delivers email, SMS, and application notifications.',
                'path' => 'notifications',
                'environment' => 'test',
                'category' => 'communications',
                'suitable_environments' => ['live', 'test', 'stg'],
                'log_templates' => ['logs/error.log', 'logs/queue.log', 'logs/delivery.log'],
            ],
            'warehouse-system' => [
                'title' => 'Warenwirtschaft Backend',
                'description' => 'Coordinates warehouse and enterprise resource operations.',
                'path' => 'warenwirtschaft',
                'environment' => 'live',
                'category' => 'operations',
                'suitable_environments' => ['live', 'stg'],
                'log_templates' => ['logs/error.log', 'logs/integration.log', 'logs/erp.log'],
            ],
            'crm' => [
                'title' => 'CRM Platform',
                'description' => 'Manages customer relationships and account activity.',
                'path' => 'crm',
                'environment' => 'live',
                'category' => 'customer',
                'suitable_environments' => ['live', 'stg', 'test'],
                'log_templates' => ['logs/error.log', 'logs/access.log', 'logs/integration.log'],
            ],
            'search-service' => [
                'title' => 'Search Service',
                'description' => 'Indexes and serves application search queries.',
                'path' => 'search',
                'environment' => 'live',
                'category' => 'api',
                'suitable_environments' => ['live', 'stg', 'test'],
                'log_templates' => ['logs/error.log', 'logs/indexer.log', 'logs/query.log'],
            ],
            'load-balancer-node' => [
                'title' => 'Load Balancer Node',
                'description' => 'Balances traffic across application instances.',
                'path' => 'load-balancer',
                'environment' => 'live',
                'category' => 'infrastructure',
                'suitable_environments' => ['live', 'stg'],
                'log_templates' => ['logs/error.log', 'logs/access.log', 'logs/health.log'],
            ],
            'shared-storage-cluster' => [
                'title' => 'Shared Storage Cluster',
                'description' => 'Provides shared storage for distributed services.',
                'path' => 'shared-storage',
                'environment' => 'live',
                'category' => 'infrastructure',
                'suitable_environments' => ['live', 'stg'],
                'log_templates' => ['logs/error.log', 'logs/replication.log', 'logs/audit.log'],
            ],
            'ci-runner' => [
                'title' => 'CI Runner',
                'description' => 'Runs continuous integration and delivery pipelines.',
                'path' => 'ci-runner',
                'environment' => 'dev',
                'category' => 'devtools',
                'suitable_environments' => ['dev', 'test'],
                'log_templates' => ['logs/error.log', 'logs/pipeline.log', 'logs/worker.log'],
            ],
            'feature-flags' => [
                'title' => 'Feature Flag Service',
                'description' => 'Controls feature rollout and runtime configuration.',
                'path' => 'feature-flags',
                'environment' => 'dev',
                'category' => 'devtools',
                'suitable_environments' => ['dev', 'test', 'stg'],
                'log_templates' => ['logs/error.log', 'logs/audit.log', 'logs/toggle.log'],
            ],
            'email-gateway' => [
                'title' => 'Email Gateway',
                'description' => 'Relays outbound email and delivery events.',
                'path' => 'email',
                'environment' => 'live',
                'category' => 'communications',
                'suitable_environments' => ['live', 'stg', 'test'],
                'log_templates' => ['logs/error.log', 'logs/delivery.log', 'logs/relay.log'],
            ],
            'reporting-service' => [
                'title' => 'Reporting Service',
                'description' => 'Builds scheduled reports and data exports.',
                'path' => 'reporting',
                'environment' => 'stg',
                'category' => 'analytics',
                'suitable_environments' => ['live', 'stg', 'test'],
                'log_templates' => ['logs/error.log', 'logs/report.log', 'logs/export.log'],
            ],
            'qa-automation' => [
                'title' => 'QA Automation Suite',
                'description' => 'Executes automated quality and regression checks.',
                'path' => 'qa-suite',
                'environment' => 'test',
                'category' => 'testing',
                'suitable_environments' => ['test', 'stg'],
                'log_templates' => ['logs/error.log', 'logs/run.log', 'logs/assertion.log'],
            ],
            'beta-portal' => [
                'title' => 'Beta Customer Portal',
                'description' => 'Preview customer portal for upcoming features.',
                'path' => 'beta-portal',
                'environment' => 'test',
                'category' => 'customer',
                'suitable_environments' => ['test', 'stg'],
                'log_templates' => ['logs/error.log', 'logs/feature.log', 'logs/access.log'],
            ],
            'catalog-service' => [
                'title' => 'Catalog Service',
                'description' => 'Maintains product catalog data and availability.',
                'path' => 'catalog',
                'environment' => 'live',
                'category' => 'commerce',
                'suitable_environments' => ['live', 'stg'],
                'log_templates' => ['logs/error.log', 'logs/ingestion.log', 'logs/search.log'],
            ],
            'message-broker' => [
                'title' => 'Message Broker',
                'description' => 'Moves asynchronous messages between services.',
                'path' => 'broker',
                'environment' => 'live',
                'category' => 'infrastructure',
                'suitable_environments' => ['live', 'test', 'stg'],
                'log_templates' => ['logs/error.log', 'logs/queue.log', 'logs/cluster.log'],
            ],
            'workflow-engine' => [
                'title' => 'Workflow Engine',
                'description' => 'Orchestrates multi-step business workflows.',
                'path' => 'workflow',
                'environment' => 'stg',
                'category' => 'internal',
                'suitable_environments' => ['dev', 'test', 'stg', 'live'],
                'log_templates' => ['logs/error.log', 'logs/execution.log', 'logs/audit.log'],
            ],
        ];

        $serverPresets = [
            'production_live' => [
                'environment_weights' => ['live' => 1.0],
                'app_count_range' => [4, 7],
                'preferred_categories' => [
                    'commerce' => 3.4,
                    'customer' => 2.4,
                    'operations' => 2.1,
                    'analytics' => 1.5,
                    'communications' => 1.6,
                    'api' => 1.8,
                    'internal' => 1.2,
                    'infrastructure' => 0.8,
                    'devtools' => 0.4,
                    'testing' => 0.4,
                ],
                'app_weight_overrides' => [
                    'ecommerce' => 6,
                    'payment-processor' => 5,
                    'inventory-system' => 4,
                    'warehouse-system' => 4,
                    'catalog-service' => 4,
                    'admin-panel' => 3,
                    'api-gateway' => 3,
                    'crm' => 2,
                    'analytics-dashboard' => 2,
                    'notification-service' => 2,
                ],
                'status_profile' => [
                    'fatal' => [
                        ['prob' => 0.9, 'range' => [0, 0]],
                        ['prob' => 0.08, 'range' => [1, 1]],
                        ['prob' => 0.02, 'range' => [2, 4]],
                    ],
                    'warnings' => [
                        ['prob' => 0.82, 'range' => [0, 1]],
                        ['prob' => 0.14, 'range' => [2, 4]],
                        ['prob' => 0.04, 'range' => [5, 10]],
                    ],
                ],
                'status_total_base' => [250, 550],
                'status_total_weight' => ['fatal' => 25, 'warnings' => 6],
                'session_multiplier' => 1.15,
                'log_files' => ['min' => 2, 'max' => 5],
                'log_size_multiplier' => 1.0,
                'live_environment_bias' => [
                    'clean_probability' => 0.86,
                    'max_fatal' => 2,
                    'max_warnings' => 5,
                    'suppress_fatal_probability' => 0.6,
                    'suppress_warning_probability' => 0.48,
                ],
                'outlier_profile' => [
                    'fatal' => [15, 45],
                    'warnings' => [30, 140],
                    'distribution' => ['warnings' => 0.7, 'fatal' => 0.2, 'both' => 0.1],
                ],
            ],
            'test_only' => [
                'environment_weights' => ['test' => 1.0],
                'app_count_range' => [3, 6],
                'preferred_categories' => [
                    'testing' => 3.6,
                    'devtools' => 2.8,
                    'communications' => 1.4,
                    'api' => 1.5,
                    'customer' => 1.2,
                    'analytics' => 1.1,
                    'infrastructure' => 0.6,
                    'internal' => 0.8,
                ],
                'app_weight_overrides' => [
                    'qa-automation' => 6,
                    'ci-runner' => 5,
                    'feature-flags' => 4,
                    'beta-portal' => 4,
                    'user-portal' => 2,
                    'notification-service' => 2,
                    'workflow-engine' => 2,
                ],
                'status_profile' => [
                    'fatal' => [
                        ['prob' => 0.45, 'range' => [0, 0]],
                        ['prob' => 0.35, 'range' => [1, 3]],
                        ['prob' => 0.20, 'range' => [4, 10]],
                    ],
                    'warnings' => [
                        ['prob' => 0.25, 'range' => [0, 2]],
                        ['prob' => 0.45, 'range' => [5, 25]],
                        ['prob' => 0.30, 'range' => [30, 90]],
                    ],
                ],
                'status_total_base' => [80, 200],
                'status_total_weight' => ['fatal' => 12, 'warnings' => 5],
                'session_multiplier' => 0.45,
                'log_files' => ['min' => 1, 'max' => 3],
                'log_size_multiplier' => 0.6,
                'outlier_profile' => [
                    'fatal' => [12, 40],
                    'warnings' => [80, 200],
                    'distribution' => ['warnings' => 0.8, 'fatal' => 0.1, 'both' => 0.1],
                ],
            ],
            'dev_test_mix' => [
                'environment_weights' => ['dev' => 0.6, 'test' => 0.4],
                'app_count_range' => [4, 7],
                'preferred_categories' => [
                    'devtools' => 3.2,
                    'testing' => 2.2,
                    'api' => 1.8,
                    'communications' => 1.3,
                    'customer' => 1.1,
                    'analytics' => 1.0,
                    'infrastructure' => 0.9,
                    'internal' => 1.0,
                ],
                'app_weight_overrides' => [
                    'feature-flags' => 4,
                    'ci-runner' => 4,
                    'qa-automation' => 3,
                    'api-gateway' => 3,
                    'user-portal' => 2,
                    'message-broker' => 2,
                    'workflow-engine' => 2,
                ],
                'status_profile' => [
                    'fatal' => [
                        ['prob' => 0.35, 'range' => [0, 1]],
                        ['prob' => 0.40, 'range' => [2, 6]],
                        ['prob' => 0.25, 'range' => [7, 18]],
                    ],
                    'warnings' => [
                        ['prob' => 0.30, 'range' => [0, 4]],
                        ['prob' => 0.45, 'range' => [5, 25]],
                        ['prob' => 0.25, 'range' => [26, 80]],
                    ],
                ],
                'status_total_base' => [120, 320],
                'status_total_weight' => ['fatal' => 18, 'warnings' => 6],
                'session_multiplier' => 0.5,
                'log_files' => ['min' => 2, 'max' => 4],
                'log_size_multiplier' => 0.7,
                'outlier_profile' => [
                    'fatal' => [25, 70],
                    'warnings' => [90, 220],
                    'distribution' => ['warnings' => 0.5, 'fatal' => 0.3, 'both' => 0.2],
                ],
            ],
            'mixed_all' => [
                'environment_weights' => ['live' => 0.4, 'stg' => 0.25, 'test' => 0.25, 'dev' => 0.1],
                'app_count_range' => [5, 8],
                'preferred_categories' => [
                    'commerce' => 2.4,
                    'customer' => 2.0,
                    'analytics' => 1.6,
                    'api' => 1.7,
                    'operations' => 1.3,
                    'communications' => 1.2,
                    'internal' => 1.1,
                    'infrastructure' => 1.0,
                    'devtools' => 0.9,
                    'testing' => 0.9,
                ],
                'app_weight_overrides' => [
                    'ecommerce' => 4,
                    'payment-processor' => 4,
                    'inventory-system' => 3,
                    'analytics-dashboard' => 3,
                    'message-broker' => 2,
                    'reporting-service' => 3,
                    'workflow-engine' => 2,
                ],
                'status_profile' => [
                    'fatal' => [
                        ['prob' => 0.48, 'range' => [0, 1]],
                        ['prob' => 0.32, 'range' => [2, 5]],
                        ['prob' => 0.20, 'range' => [6, 15]],
                    ],
                    'warnings' => [
                        ['prob' => 0.38, 'range' => [0, 4]],
                        ['prob' => 0.40, 'range' => [5, 25]],
                        ['prob' => 0.22, 'range' => [26, 90]],
                    ],
                ],
                'status_total_base' => [150, 420],
                'status_total_weight' => ['fatal' => 20, 'warnings' => 6],
                'session_multiplier' => 0.95,
                'log_files' => ['min' => 2, 'max' => 5],
                'log_size_multiplier' => 0.9,
                'outlier_profile' => [
                    'fatal' => [20, 60],
                    'warnings' => [80, 250],
                    'distribution' => ['warnings' => 0.55, 'fatal' => 0.25, 'both' => 0.2],
                ],
            ],
            'staging_live' => [
                'environment_weights' => ['stg' => 0.55, 'live' => 0.45],
                'app_count_range' => [4, 7],
                'preferred_categories' => [
                    'commerce' => 2.2,
                    'customer' => 2.0,
                    'analytics' => 1.6,
                    'internal' => 1.4,
                    'operations' => 1.8,
                    'communications' => 1.2,
                    'api' => 1.3,
                    'infrastructure' => 1.0,
                    'devtools' => 0.6,
                    'testing' => 0.7,
                ],
                'app_weight_overrides' => [
                    'catalog-service' => 4,
                    'inventory-system' => 3,
                    'warehouse-system' => 3,
                    'reporting-service' => 3,
                    'api-gateway' => 2,
                    'workflow-engine' => 2,
                    'shared-storage-cluster' => 2,
                ],
                'status_profile' => [
                    'fatal' => [
                        ['prob' => 0.58, 'range' => [0, 1]],
                        ['prob' => 0.28, 'range' => [2, 4]],
                        ['prob' => 0.14, 'range' => [5, 12]],
                    ],
                    'warnings' => [
                        ['prob' => 0.40, 'range' => [0, 3]],
                        ['prob' => 0.40, 'range' => [4, 18]],
                        ['prob' => 0.20, 'range' => [19, 70]],
                    ],
                ],
                'status_total_base' => [130, 360],
                'status_total_weight' => ['fatal' => 18, 'warnings' => 6],
                'session_multiplier' => 0.85,
                'log_files' => ['min' => 2, 'max' => 4],
                'log_size_multiplier' => 0.85,
                'outlier_profile' => [
                    'fatal' => [18, 50],
                    'warnings' => [60, 180],
                    'distribution' => ['warnings' => 0.5, 'fatal' => 0.2, 'both' => 0.3],
                ],
            ],
            'infra_mix' => [
                'environment_weights' => ['live' => 0.4, 'stg' => 0.3, 'test' => 0.2, 'dev' => 0.1],
                'app_count_range' => [3, 6],
                'preferred_categories' => [
                    'infrastructure' => 3.5,
                    'operations' => 2.0,
                    'api' => 1.4,
                    'analytics' => 1.2,
                    'internal' => 1.1,
                    'communications' => 1.0,
                    'commerce' => 0.6,
                    'customer' => 0.6,
                    'devtools' => 1.2,
                ],
                'app_weight_overrides' => [
                    'load-balancer-node' => 6,
                    'shared-storage-cluster' => 4,
                    'message-broker' => 4,
                    'analytics-dashboard' => 2,
                    'workflow-engine' => 2,
                    'notification-service' => 2,
                ],
                'status_profile' => [
                    'fatal' => [
                        ['prob' => 0.40, 'range' => [0, 1]],
                        ['prob' => 0.35, 'range' => [2, 5]],
                        ['prob' => 0.25, 'range' => [6, 15]],
                    ],
                    'warnings' => [
                        ['prob' => 0.30, 'range' => [0, 3]],
                        ['prob' => 0.40, 'range' => [4, 20]],
                        ['prob' => 0.30, 'range' => [21, 80]],
                    ],
                ],
                'status_total_base' => [90, 260],
                'status_total_weight' => ['fatal' => 16, 'warnings' => 5],
                'session_multiplier' => 0.4,
                'log_files' => ['min' => 1, 'max' => 4],
                'log_size_multiplier' => 0.7,
                'outlier_profile' => [
                    'fatal' => [25, 70],
                    'warnings' => [70, 180],
                    'distribution' => ['warnings' => 0.5, 'fatal' => 0.3, 'both' => 0.2],
                ],
            ],
        ];

        $presetKeyMap = [
            'production-web' => 'production_live',
            'database-server' => 'mixed_all',
            'staging-server' => 'test_only',
            'development-server' => 'dev_test_mix',
            'backup-server' => 'staging_live',
            'load-balancer' => 'infra_mix',
        ];

        $presetKey = $presetKeyMap[$this->serverName] ?? 'mixed_all';
        $preset = $serverPresets[$presetKey];

        $environmentSessionProfiles = [
            'live' => ['hour' => [300, 1800], 'day' => [4000, 22000], 'total' => [50000, 280000]],
            'stg' => ['hour' => [60, 200], 'day' => [800, 4000], 'total' => [9000, 60000]],
            'test' => ['hour' => [20, 120], 'day' => [300, 1600], 'total' => [4000, 25000]],
            'dev' => ['hour' => [10, 80], 'day' => [200, 900], 'total' => [2500, 15000]],
        ];

        /** @var array<string,float> $categorySessionMultipliers */
        $categorySessionMultipliers = [
            'commerce' => 1.3,
            'customer' => 1.2,
            'analytics' => 1.1,
            'api' => 1.0,
            'operations' => 0.9,
            'communications' => 0.8,
            'internal' => 0.7,
            'infrastructure' => 0.4,
            'devtools' => 0.5,
            'testing' => 0.6,
        ];

        $logSizeBase = [
            'live' => [600, 50000],
            'stg' => [200, 18000],
            'test' => [120, 12000],
            'dev' => [80, 8000],
        ];

        $pickWeightedKey = static function (array $weights): string {
            $filtered = [];
            foreach ($weights as $key => $weight) {
                if ($weight > 0) {
                    $filtered[$key] = $weight;
                }
            }
            if (empty($filtered)) {
                $keys = array_keys($weights);

                return $keys[array_rand($keys)];
            }

            $sum = array_sum($filtered);
            $max = max(1, (int) round($sum * 1000));
            $rand = mt_rand(1, $max);
            $running = 0;

            foreach ($filtered as $key => $weight) {
                $running += (int) round($weight * 1000);
                if ($rand <= $running) {
                    return $key;
                }
            }

            $keys = array_keys($filtered);

            return $keys[array_rand($keys)];
        };

        $selectWeightedUnique = static function (array $weights, int $count) use ($pickWeightedKey): array {
            $selected = [];
            $pool = $weights;

            while (count($selected) < $count && !empty($pool)) {
                $choice = $pickWeightedKey($pool);
                $selected[] = $choice;
                unset($pool[$choice]);
            }

            return $selected;
        };

        $randomFromRange = static function (array $range, float $multiplier = 1.0): int {
            $min = max(0, (int) round($range[0] * $multiplier));
            $max = max($min, (int) round($range[1] * $multiplier));

            return $min === $max ? $min : mt_rand($min, $max);
        };

        $rollProfile = static function (array $profile) {
            if (empty($profile)) {
                return 0;
            }

            $probabilities = [];
            foreach ($profile as $index => $entry) {
                $probabilities[$index] = max($entry['prob'] ?? 0, 0);
            }

            $sum = array_sum($probabilities);
            if ($sum <= 0) {
                $entry = reset($profile);
                [$min, $max] = $entry['range'];

                return $min >= $max ? $min : mt_rand($min, $max);
            }

            $rand = mt_rand(1, 1000);
            $cumulative = 0;

            foreach ($profile as $index => $entry) {
                $prob = $probabilities[$index];
                if ($prob <= 0) {
                    continue;
                }

                $cumulative += (int) round($prob / $sum * 1000);
                if ($rand <= $cumulative) {
                    [$min, $max] = $entry['range'];

                    return $min >= $max ? $min : mt_rand($min, $max);
                }
            }

            $entry = end($profile);
            [$min, $max] = $entry['range'];

            return $min >= $max ? $min : mt_rand($min, $max);
        };

        $appWeights = [];
        foreach ($appTemplates as $key => $template) {
            $category = $template['category'] ?? 'misc';
            $weight = $template['base_weight'] ?? 1.0;

            if (isset($preset['preferred_categories'][$category])) {
                $weight *= $preset['preferred_categories'][$category];
            }

            if (isset($preset['app_weight_overrides'][$key])) {
                $weight = max($weight, $preset['app_weight_overrides'][$key]);
            }

            $appWeights[$key] = max($weight, 0.1);
        }

        $maxPossible = min($preset['app_count_range'][1], count($appTemplates));
        $minPossible = min($preset['app_count_range'][0], $maxPossible);
        $appCount = mt_rand($minPossible, $maxPossible);
        $selectedAppKeys = $selectWeightedUnique($appWeights, $appCount);

        $apps = [];
        $unconfiguredAssigned = false;
        $errorSessionAssigned = false;
        $lastAppKey = $selectedAppKeys[count($selectedAppKeys) - 1] ?? null;

        foreach ($selectedAppKeys as $appKey) {
            $template = $appTemplates[$appKey];
            $category = $template['category'] ?? 'misc';

            $allowedEnvironments = $template['suitable_environments'] ?? array_keys($preset['environment_weights']);
            $intersected = array_intersect($allowedEnvironments, array_keys($preset['environment_weights']));

            $allowedEnvironments = array_values($intersected);
            if (empty($allowedEnvironments)) {
                $allowedEnvironments = array_keys($preset['environment_weights']);
            }

            $envWeights = array_intersect_key($preset['environment_weights'], array_flip($allowedEnvironments));

            if (empty($envWeights)) {
                $envWeights = $preset['environment_weights'];
            }

            $environment = $pickWeightedKey($envWeights);

            $fatalCount = $rollProfile($preset['status_profile']['fatal']);
            $warningCount = $rollProfile($preset['status_profile']['warnings']);

            if ($environment === 'live' && isset($preset['live_environment_bias'])) {
                $bias = $preset['live_environment_bias'];
                $cleanProbability = max(0.0, min(1.0, (float) ($bias['clean_probability'] ?? 0.8)));
                if (mt_rand(1, 1000) <= (int) round($cleanProbability * 1000)) {
                    $fatalCount = 0;
                    $warningCount = 0;
                } else {
                    $maxFatal = isset($bias['max_fatal']) ? max(0, (int) $bias['max_fatal']) : null;
                    $maxWarnings = isset($bias['max_warnings']) ? max(0, (int) $bias['max_warnings']) : null;

                    if ($maxFatal !== null && $fatalCount > $maxFatal) {
                        $fatalCount = mt_rand(0, $maxFatal);
                    } elseif ($fatalCount > 0) {
                        $suppressFatal = max(0.0, min(1.0, (float) ($bias['suppress_fatal_probability'] ?? 0.55)));
                        if (mt_rand(1, 1000) <= (int) round($suppressFatal * 1000)) {
                            $fatalCount = 0;
                        }
                    }

                    if ($maxWarnings !== null && $warningCount > $maxWarnings) {
                        $warningCount = mt_rand(0, $maxWarnings);
                    } elseif ($warningCount > 0) {
                        $suppressWarnings = max(0.0, min(1.0, (float) ($bias['suppress_warning_probability'] ?? 0.45)));
                        if (mt_rand(1, 1000) <= (int) round($suppressWarnings * 1000)) {
                            $warningCount = mt_rand(0, $warningCount);
                        }
                    }
                }
            }

            $statusBaseRange = $preset['status_total_base'] ?? [150, 400];
            $statusTotal = $randomFromRange($statusBaseRange, $preset['session_multiplier'] ?? 1.0);
            $statusWeights = $preset['status_total_weight'] ?? ['fatal' => 18, 'warnings' => 5];
            $statusTotal += (int) round($fatalCount * ($statusWeights['fatal'] ?? 18));
            $statusTotal += (int) round($warningCount * ($statusWeights['warnings'] ?? 5));
            $statusTotal = max($statusTotal, $fatalCount + $warningCount);

            $sessionProfile = $environmentSessionProfiles[$environment] ?? $environmentSessionProfiles['live'];
            $categoryMultiplier = $categorySessionMultipliers[$category] ?? 0.8;
            $sessionMultiplier = ($preset['session_multiplier'] ?? 1.0) * $categoryMultiplier;

            $sessionsHour = max(0, $randomFromRange($sessionProfile['hour'], $sessionMultiplier));
            $sessionsDay = max($sessionsHour * mt_rand(12, 24), $randomFromRange($sessionProfile['day'], $sessionMultiplier));
            $sessionsTotal = max($sessionsDay * mt_rand(6, 12), $randomFromRange($sessionProfile['total'], $sessionMultiplier));

            $session = [
                'type' => 'file',
                'status' => 'ok',
                'count_last_hour' => $sessionsHour,
                'count_last_day' => $sessionsDay,
                'count_total' => $sessionsTotal,
                'path' => '/var/lib/php/sessions',
                'size_kb' => round($sessionsTotal * 1.8, 1),
            ];

            if ($environment === 'dev' && !$unconfiguredAssigned) {
                $session = [
                    'type' => 'none',
                    'status' => 'unconfigured',
                    'count_last_hour' => null,
                    'count_last_day' => null,
                    'count_total' => null,
                    'path' => null,
                    'size_kb' => null,
                ];
                $unconfiguredAssigned = true;
            } elseif ($environment === 'live' && mt_rand(1, 100) <= 25) {
                $session = [
                    'type' => 'redis',
                    'status' => 'ok',
                    'count_last_hour' => null,
                    'count_last_day' => null,
                    'count_total' => $sessionsTotal,
                    'path' => null,
                    'size_kb' => null,
                ];
            }

            if ($appKey === $lastAppKey && $session['status'] !== 'unconfigured') {
                $session = [
                    'type' => 'memcached',
                    'status' => 'unsupported',
                    'count_last_hour' => null,
                    'count_last_day' => null,
                    'count_total' => null,
                    'path' => null,
                    'size_kb' => null,
                    'error' => 'session handler memcached is not supported',
                ];
                $errorSessionAssigned = true;
            }

            $logCandidates = $template['log_templates'] ?? ['logs/error.log', 'logs/debug.log', 'logs/app.log'];
            $maxLogs = min(count($logCandidates), $preset['log_files']['max'] ?? count($logCandidates));
            $minLogs = max(1, min($preset['log_files']['min'] ?? 1, $maxLogs));
            $logCount = mt_rand($minLogs, $maxLogs);
            $logSelection = $logCandidates;
            shuffle($logSelection);
            $logSelection = array_slice($logSelection, 0, $logCount);

            $logSizeMultiplier = $preset['log_size_multiplier'] ?? 1.0;
            $logBaseRange = $logSizeBase[$environment] ?? [100, 8000];
            $logs = [];
            foreach ($logSelection as $logRelativePath) {
                $rangeMin = (int) max(50, round($logBaseRange[0] * $logSizeMultiplier));
                $rangeMax = (int) max($rangeMin + 10, round($logBaseRange[1] * $logSizeMultiplier));
                $size = mt_rand($rangeMin, $rangeMax);
                $logs[$template['path'] . '/' . ltrim($logRelativePath, '/')] = (string) $size;
            }

            $apps[$appKey] = [
                'title' => $template['title'],
                'description' => $template['description'] ?? 'Application monitored by Mata.',
                'path' => $template['path'],
                'url' => rtrim($this->serverConfig['url'], '/') . '/' . $template['path'],
                'status_url' => rtrim($this->serverConfig['url'], '/') . '/' . $template['path'] . '/status.php',
                'env' => $environment,
                'status' => [
                    'fatal' => $fatalCount,
                    'warnings' => $warningCount,
                    'total' => $statusTotal,
                ],
                'session' => $session,
                'logs' => $logs,
            ];
        }

        if (!$errorSessionAssigned) {
            foreach (array_reverse(array_keys($apps)) as $appKey) {
                if (($apps[$appKey]['session']['status'] ?? null) === 'unconfigured') {
                    continue;
                }

                $apps[$appKey]['session'] = [
                    'type' => 'memcached',
                    'status' => 'unsupported',
                    'count_last_hour' => null,
                    'count_last_day' => null,
                    'count_total' => null,
                    'path' => null,
                    'size_kb' => null,
                    'error' => 'session handler memcached is not supported',
                ];
                $errorSessionAssigned = true;

                break;
            }
        }

        $appKeys = array_keys($apps);
        $totalApps = count($appKeys);
        if ($totalApps > 0) {
            $outlierTargets = [];
            $groupSize = 6;

            for ($offset = 0; $offset < $totalApps; $offset += $groupSize) {
                $chunk = array_slice($appKeys, $offset, $groupSize);
                if (empty($chunk)) {
                    continue;
                }

                $chunkCount = count($chunk);
                $outlierCount = min($chunkCount, mt_rand(1, min(2, $chunkCount)));
                $chunkShuffled = $chunk;
                shuffle($chunkShuffled);
                $outlierTargets = array_merge($outlierTargets, array_slice($chunkShuffled, 0, $outlierCount));
            }

            $outlierProfile = $preset['outlier_profile'] ?? [];
            $fatalRange = $outlierProfile['fatal'] ?? [25, 60];
            $warningRange = $outlierProfile['warnings'] ?? [70, 200];
            $distribution = $outlierProfile['distribution'] ?? ['warnings' => 0.5, 'fatal' => 0.3, 'both' => 0.2];

            foreach (array_unique($outlierTargets) as $appKey) {
                if (!isset($apps[$appKey])) {
                    continue;
                }

                $outlierType = $pickWeightedKey([
                    'fatal' => $distribution['fatal'] ?? 0.3,
                    'warnings' => $distribution['warnings'] ?? 0.5,
                    'both' => $distribution['both'] ?? 0.2,
                ]);

                if ($outlierType === 'fatal' || $outlierType === 'both') {
                    $apps[$appKey]['status']['fatal'] = max(
                        $apps[$appKey]['status']['fatal'],
                        $randomFromRange($fatalRange, 1.0)
                    );
                }

                if ($outlierType === 'warnings' || $outlierType === 'both') {
                    $apps[$appKey]['status']['warnings'] = max(
                        $apps[$appKey]['status']['warnings'],
                        $randomFromRange($warningRange, 1.0)
                    );
                }

                $statusWeights = $preset['status_total_weight'] ?? ['fatal' => 18, 'warnings' => 5];
                $apps[$appKey]['status']['total'] = max(
                    $apps[$appKey]['status']['total'],
                    (int) round($apps[$appKey]['status']['fatal'] * ($statusWeights['fatal'] ?? 18)
                        + $apps[$appKey]['status']['warnings'] * ($statusWeights['warnings'] ?? 5))
                );

            }
        }

        foreach ($apps as &$app) {
            $app['status']['total'] = max(
                $app['status']['total'],
                $app['status']['fatal'] + $app['status']['warnings']
            );
        }
        unset($app);

        return $apps;
    }

    /**
     * Generate deterministic raw node-shaped Composer audit data for an app.
     *
     * @return array<string,mixed>
     */
    private function generateComposerAuditForApp(string $app): array
    {
        mt_srand((int) crc32($this->serverName . $app));

        try {
            if (mt_rand(1, 6) === 1) {
                return ['status' => 'error', 'message' => 'composer audit timed out'];
            }

            $libraries = $this->generateLibrariesForApp($app);
            shuffle($libraries);
            $packageCount = mt_rand(0, min(3, count($libraries)));
            $advisories = [];
            $titles = [
                'SQL injection in query builder',
                'XSS in template rendering',
                'Path traversal in file handler',
            ];
            $severities = ['low', 'low', 'medium', 'medium', 'medium', 'high', 'critical'];

            foreach (array_slice($libraries, 0, $packageCount) as $library) {
                $packageAdvisories = [];
                for ($i = 0; $i < mt_rand(1, 2); ++$i) {
                    $number = mt_rand(10000, 99999);
                    $packageAdvisories[] = [
                        'advisoryId' => 'PKSA-' . mt_rand(1000, 9999),
                        'cve' => "CVE-2025-{$number}",
                        'title' => $titles[array_rand($titles)],
                        'link' => 'https://github.com/advisories/GHSA-' . substr(strtoupper(dechex(mt_rand())), 0, 8),
                        'affectedVersions' => '>=1.0,<1.4.2',
                        'severity' => $severities[array_rand($severities)],
                        'reportedAt' => sprintf('2025-%02d-%02dT12:00:00+00:00', mt_rand(1, 12), mt_rand(1, 28)),
                    ];
                }
                $advisories[$library['name']] = $packageAdvisories;
            }

            $abandoned = [];
            $abandonedSeed = abs((int) crc32($this->serverName . ':' . $app));
            if ($libraries !== [] && $abandonedSeed % 4 === 0) {
                $library = $libraries[$abandonedSeed % count($libraries)];
                $abandoned[$library['name']] = $abandonedSeed % 8 === 0 ? 'replacement/package' : null;
            }

            return [
                'status' => 'ok',
                'exit_code' => $advisories === [] ? 0 : 1,
                'audit' => ['advisories' => $advisories, 'abandoned' => $abandoned],
            ];
        } finally {
            mt_srand();
        }
    }

    /**
     * Generate libraries for an app.
     *
     * @return array<int,array<string,string>>
     */
    private function generateLibrariesForApp(string $app): array
    {
        $commonLibraries = [
            [
                'name' => 'symfony/symfony',
                'version' => 'v6.' . mt_rand(1, 4) . '.' . mt_rand(0, 15),
                'description' => 'PHP framework for web applications and a set of reusable PHP components',
            ],
            [
                'name' => 'doctrine/orm',
                'version' => 'v2.' . mt_rand(10, 17) . '.' . mt_rand(0, 8),
                'description' => 'Object-Relational-Mapper for PHP',
            ],
            [
                'name' => 'twig/twig',
                'version' => 'v3.' . mt_rand(4, 8) . '.' . mt_rand(0, 5),
                'description' => 'Flexible, fast, and secure template engine for PHP',
            ],
            [
                'name' => 'monolog/monolog',
                'version' => 'v3.' . mt_rand(0, 5) . '.' . mt_rand(0, 12),
                'description' => 'Sends your logs to files, sockets, inboxes, databases and various web services',
            ],
            [
                'name' => 'guzzlehttp/guzzle',
                'version' => 'v7.' . mt_rand(4, 8) . '.' . mt_rand(0, 5),
                'description' => 'Guzzle is a PHP HTTP client library',
            ],
            [
                'name' => 'phpunit/phpunit',
                'version' => 'v10.' . mt_rand(0, 5) . '.' . mt_rand(0, 8),
                'description' => 'The PHP Unit Testing framework',
            ],
            [
                'name' => 'league/commonmark',
                'version' => 'v2.' . mt_rand(3, 4) . '.' . mt_rand(0, 8),
                'description' => 'Highly-extensible PHP Markdown parser which fully supports the CommonMark spec',
            ],
            [
                'name' => 'nesbot/carbon',
                'version' => 'v2.' . mt_rand(65, 72) . '.' . mt_rand(0, 8),
                'description' => 'A simple API extension for DateTime',
            ],
            [
                'name' => 'ramsey/uuid',
                'version' => 'v4.' . mt_rand(6, 7) . '.' . mt_rand(0, 5),
                'description' => 'A PHP library for generating and working with UUIDs',
            ],
        ];

        // Select random libraries
        $libCount = mt_rand(4, 8);
        shuffle($commonLibraries);

        return array_slice($commonLibraries, 0, $libCount);
    }

    /**
     * Generate README content for an app.
     */
    private function generateReadmeContent(string $app): string
    {
        $projectReadme = $this->getProjectReadmeContent();
        if ($projectReadme !== null) {
            return $projectReadme;
        }

        $appTitle = ucfirst(str_replace('-', ' ', $app));

        return "# {$appTitle}

            ## Overview
            This is the {$appTitle} application running on {$this->serverName}.

            ## Features
            - User authentication and authorization
            - RESTful API endpoints
            - Database integration with MySQL
            - Caching layer for improved performance
            - Comprehensive logging and monitoring

            ## Installation
            ```bash
            composer install
            npm install
            php artisan migrate
            ```

            ## Configuration
            Copy `.env.example` to `.env` and update the configuration values.

            ## Usage
            Start the development server:
            ```bash
            php artisan serve
            ```

            ## API Documentation
            The API endpoints are available at `/api/v1/` with the following resources:
            - Users: `/api/v1/users`
            - Products: `/api/v1/products`
            - Orders: `/api/v1/orders`

            ## Monitoring
            This application is monitored by MATA Dashboard. Key metrics include:
            - Response time
            - Error rates
            - Database query performance
            - Cache hit ratios

            ## Support
            For issues and support, contact the development team.

            ---
            Last updated: " . date('Y-m-d H:i:s') . '
            Generated for demo purposes by MATA Dashboard MockServerDataCollector.';
    }

    /**
     * Load README.md content from the project root once per request.
     */
    private function getProjectReadmeContent(): ?string
    {
        if ($this->projectReadme !== null) {
            return $this->projectReadme === '' ? null : $this->projectReadme;
        }

        $readmePath = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'README.md';
        if (!is_readable($readmePath)) {
            $this->logger->warning('Demo README not available', ['path' => $readmePath]);
            $this->projectReadme = '';

            return null;
        }

        $contents = file_get_contents($readmePath);
        if ($contents === false) {
            $this->logger->warning('Failed to read project README for demo mode', ['path' => $readmePath]);
            $this->projectReadme = '';

            return null;
        }

        $this->projectReadme = $contents;

        return $this->projectReadme;
    }
}
