<?php

declare(strict_types=1);

namespace Mata\Services\Collect;

use Mata\Constants\CacheType;
use Mata\Services\Cache\CacheTtlResolver;
use Mata\Services\Collect\Demo\DemoAppCatalog;
use Mata\Services\Collect\Demo\DemoAppGenerator;
use Mata\Services\Collect\Demo\DemoServerCatalog;
use Mata\Services\Collect\Demo\WeightedRandom;
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
    private NodeResponseNormalizer $responseNormalizer;
    private string $serverName;

    /** @var array<string,mixed> */
    private array $serverConfig;
    private int $cacheAge = 0;
    private ?string $projectReadme = null;
    private DemoServerCatalog $serverCatalog;
    private DemoAppCatalog $appCatalog;
    private DemoAppGenerator $appGenerator;

    /**
     * Initialize mock server data collector.
     *
     * @param LoggerInterface        $logger             PSR-3 logger for demo logging
     * @param CacheTtlResolver       $ttlResolver        Cache TTL configuration lookup
     * @param NodeResponseNormalizer $responseNormalizer Node response normalizer
     * @param string                 $serverName         Server identifier for demo data generation
     * @param array<string,mixed>    $serverConfig       Server configuration from demo-servers.json
     */
    public function __construct(
        LoggerInterface $logger,
        CacheTtlResolver $ttlResolver,
        NodeResponseNormalizer $responseNormalizer,
        string $serverName,
        array $serverConfig,
    ) {
        $this->logger = $logger;
        $this->ttlResolver = $ttlResolver;
        $this->responseNormalizer = $responseNormalizer;
        $this->serverName = $serverName;
        $this->serverConfig = $serverConfig;
        $this->cacheAge = mt_rand(10, 180); // Simulate cache age
        $this->serverCatalog = new DemoServerCatalog();
        $this->appCatalog = new DemoAppCatalog();
        $this->appGenerator = new DemoAppGenerator($this->appCatalog, new WeightedRandom());
    }

    /**
     * Generate realistic server system metrics.
     */
    public function getServerSystemData(CacheType $cacheType = CacheType::ServerData, bool $forceRefresh = false): ?array
    {
        $ttlSeconds = $this->ttlResolver->get($cacheType);

        // Generate realistic metrics based on server type
        $baseMetrics = $this->serverCatalog->baseMetricsForServerType($this->serverName);

        $data = [
            'cpu_usage' => $this->serverName === 'production-web'
                ? 97
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

        return $this->responseNormalizer->normalizeApiResponse($data, $this->cacheAge, $ttlSeconds, false, false, 'server/data');
    }

    /**
     * Generate demo server information.
     */
    public function getServerInfo(CacheType $cacheType = CacheType::ServerInfo): ?array
    {
        $ttlSeconds = $this->ttlResolver->get($cacheType);

        $osOptions = $this->serverCatalog->osOptions();
        $commonPhpExtensions = $this->serverCatalog->commonPhpExtensions();
        $optionalExtensions = $this->serverCatalog->optionalPhpExtensions();

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

        return $this->responseNormalizer->normalizeApiResponse($data, $this->cacheAge, $ttlSeconds, false);
    }

    /**
     * Generate demo application data.
     */
    public function getApps(CacheType $cacheType = CacheType::Apps, bool $forceRefresh = false): ?array
    {
        $ttlSeconds = $this->ttlResolver->get($cacheType);
        $data = $this->appGenerator->generate($this->serverName, $this->serverConfig);

        return $this->responseNormalizer->normalizeApiResponse(
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
    public function getServerPhpProcesses(CacheType $cacheType = CacheType::ServerProcesses): ?array
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

        return $this->responseNormalizer->normalizeApiResponse($processes, $this->cacheAge, $ttlSeconds, false);
    }

    /**
     * Generate demo database information.
     */
    public function getServerDatabases(CacheType $cacheType = CacheType::Databases): array|string|null
    {
        $ttlSeconds = $this->ttlResolver->get($cacheType);

        $data = $this->serverCatalog->serverDatabases();

        return $this->responseNormalizer->normalizeApiResponse($data, $this->cacheAge, $ttlSeconds, false, false, 'server/databases');
    }

    /**
     * Generate demo libraries for all apps.
     */
    public function getAppsLibraries(CacheType $cacheType = CacheType::Libraries): ?array
    {
        $ttlSeconds = $this->ttlResolver->get($cacheType);

        $apps = array_keys($this->appGenerator->generate($this->serverName, $this->serverConfig));
        $libraries = [];

        foreach ($apps as $app) {
            $libraries[$app] = $this->generateLibrariesForApp($app);
        }

        return $this->responseNormalizer->normalizeApiResponse($libraries, $this->cacheAge, $ttlSeconds, false, false, 'apps/libraries');
    }

    /**
     * Generate demo libraries for specific app.
     */
    public function getAppLibraries(string $app, CacheType $cacheType = CacheType::Libraries): ?array
    {
        $ttlSeconds = $this->ttlResolver->get($cacheType);
        $data = $this->generateLibrariesForApp($app);

        return $this->responseNormalizer->normalizeApiResponse($data, $this->cacheAge, $ttlSeconds, false, false, "{$app}/libraries");
    }

    /**
     * Generate demo README content.
     */
    public function fetchAppReadme(string $app, CacheType $cacheType = CacheType::Readme): ?array
    {
        $ttlSeconds = $this->ttlResolver->get($cacheType);

        $readmeContent = $this->generateReadmeContent($app);

        return $this->responseNormalizer->normalizeApiResponse($readmeContent, $this->cacheAge, $ttlSeconds, false);
    }

    /**
     * Generate demo grouped log content matching new API structure.
     */
    public function fetchAppLogs(string $app, CacheType $cacheType = CacheType::AppLogs): ?array
    {
        $ttlSeconds = $this->ttlResolver->get($cacheType);

        $logFiles = [
            "{$app}/logs/error.log",
            "{$app}/logs/access.log",
            "{$app}/logs/debug.log",
        ];

        $groupedLogs = [];

        $errorTypes = $this->appCatalog->logErrorTypes();

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

        return $this->responseNormalizer->normalizeApiResponse(
            ['entries' => $groupedLogs, 'readout_limit' => 200],
            $this->cacheAge,
            $ttlSeconds,
            false,
            false,
            "{$app}/logs"
        );
    }

    /**
     * Generate demo log file metadata.
     */
    public function fetchAppLogsStatus(string $app, CacheType $cacheType = CacheType::AppLogs): ?array
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

        return $this->responseNormalizer->normalizeApiResponse($logsStatus, $this->cacheAge, $ttlSeconds, false);
    }

    /**
     * Fetch session status for a specific app.
     *
     * Returns the nested session object: `type`, `status`, `count_last_hour`,
     * `count_last_day`, `count_total`, `path`, `size_kb`, and `error` when
     * status is error/unsupported.
     *
     * @param string    $app       App identifier
     * @param CacheType $cacheType Cache type key for TTL lookup (default: CacheType::Sessions)
     *
     * @return null|array<string,mixed> Session data or null on failure
     */
    public function fetchAppSessions(string $app, CacheType $cacheType = CacheType::Sessions): ?array
    {
        $ttlSeconds = $this->ttlResolver->get($cacheType);

        $sessionVariants = $this->appCatalog->sessionVariants();

        $sessions = $sessionVariants[abs((int) crc32($app)) % count($sessionVariants)];

        return $this->responseNormalizer->normalizeApiResponse($sessions, $this->cacheAge, $ttlSeconds, false, path: "{$app}/sessions");
    }

    /**
     * Generate demo Composer audit results for a specific app.
     *
     * @return null|array<string,mixed> Normalized Composer audit response
     */
    public function fetchAppComposerAudit(string $app, CacheType $cacheType = CacheType::Audit): ?array
    {
        $ttlSeconds = $this->ttlResolver->get($cacheType);

        return $this->responseNormalizer->normalizeApiResponse(
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
        $ttlSeconds = $this->ttlResolver->get(CacheType::Audit);
        $cacheAge = 14400 + (abs((int) crc32($this->serverName . $app)) % 14400);

        return $this->responseNormalizer->normalizeApiResponse(
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
     * @param CacheType $cacheType Cache type key for TTL lookup (default: CacheType::Cronjobs)
     *
     * @return null|array<string,mixed> Normalized response with cronjob entries or null on failure
     */
    public function fetchServerCronjobs(CacheType $cacheType = CacheType::Cronjobs): ?array
    {
        $ttlSeconds = $this->ttlResolver->get($cacheType);

        $cronjobs = $this->serverCatalog->cronjobs();

        return $this->responseNormalizer->normalizeApiResponse($cronjobs, $this->cacheAge, $ttlSeconds, false, path: 'server/cronjobs');
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
        $commonLibraries = $this->appCatalog->commonLibraries();

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
