<?php

declare(strict_types=1);

namespace Mata\Controllers;

use Exception;
use InvalidArgumentException;
use Mata\Config\Dto\ApplicationConfig;
use Mata\Core\App;
use Mata\Http\Responses\HtmlResponse;
use Mata\Http\Responses\JsonResponse;
use Mata\Http\Responses\Response;
use Mata\Http\Routing\Endpoints;
use Mata\Services\Cache\ApiCacheKeyFactory;
use Mata\Services\Cache\CacheStoreInterface;
use Mata\Services\Cache\CacheTtlResolver;
use Mata\Services\Cache\CacheTypeResolver;
use Mata\Services\Cache\ModalCacheService;
use Mata\Services\Collect\MockServerDataCollector;
use Mata\Services\Collect\NodeApiClient;
use Mata\Services\Collect\NodeResponseNormalizer;
use Mata\Services\Collect\ServerDataCollector;
use Mata\Services\Collect\ServerDataCollectorInterface;
use Mata\Services\DataTransformers\LogDataTransformer;
use Mata\Utils\Tools;
use Mata\Utils\TypeConverter;
use Mata\Views\ModalRenderer;
use Monolog\Logger;
use Twig\Environment;

/**
 * Modal content controller for dynamic AJAX responses.
 *
 * Handles coordination of modal content generation:
 * - API data fetching from remote servers
 * - Request validation and error handling
 * - Cache management and TTL configuration
 * - Response formatting (HTML/JSON)
 *
 * Rendering logic is handled by ModalRenderer in src/View/
 */
class ModalController extends BaseController
{
    private CacheStoreInterface $cacheStore;
    private ApiCacheKeyFactory $cacheKeyFactory;
    private CacheTtlResolver $ttlResolver;
    private ModalRenderer $modalRenderer;
    private ModalCacheService $modalCacheService;
    private LogDataTransformer $logTransformer;
    private Endpoints $endpoints;

    /** @var array<string,ServerDataCollectorInterface> */
    private array $serverDataCollectors = [];

    /** @var array<string,mixed> */
    private array $serversConfig;

    private ApplicationConfig $appConfig;

    /**
     * Initialize modal controller with dependencies.
     *
     * @param ApplicationConfig   $appConfig       Application configuration DTO
     * @param array<string,mixed> $serversConfig   Server configuration array
     * @param CacheStoreInterface $cacheStore      Cache persistence service
     * @param ApiCacheKeyFactory  $cacheKeyFactory API cache key factory
     * @param CacheTtlResolver    $ttlResolver     Cache TTL configuration lookup
     * @param LogDataTransformer  $logTransformer  Log data transformation service
     * @param Endpoints           $endpoints       Route endpoints for URL generation
     */
    public function __construct(
        App $app,
        Tools $tools,
        Environment $twig,
        Logger $logger,
        ApplicationConfig $appConfig,
        CacheStoreInterface $cacheStore,
        ApiCacheKeyFactory $cacheKeyFactory,
        CacheTtlResolver $ttlResolver,
        LogDataTransformer $logTransformer,
        Endpoints $endpoints,
        array $serversConfig
    ) {
        $this->logger = $logger;
        $this->appConfig = $appConfig;
        $this->cacheStore = $cacheStore;
        $this->cacheKeyFactory = $cacheKeyFactory;
        $this->ttlResolver = $ttlResolver;
        $this->logTransformer = $logTransformer;
        $this->endpoints = $endpoints;
        $this->serversConfig = $serversConfig;
        $cacheTypeResolver = new CacheTypeResolver();
        $this->modalCacheService = new ModalCacheService($cacheTypeResolver);

        // Initialize ModalRenderer
        $this->modalRenderer = new ModalRenderer($twig, $logger, $tools, $app, $endpoints);

        foreach ($this->serversConfig as $server => $serverConfig) {
            try {
                $this->serverDataCollectors[$server] = $this->createServerDataCollector($server, $serverConfig);
            } catch (Exception $e) {
                $this->logger->error("{$server}", [
                    'msg' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);
            }
        }
    }

    /**
     * Create appropriate server data collector based on demo mode.
     *
     * @param string              $server       Server name
     * @param array<string,mixed> $serverConfig Server configuration
     */
    private function createServerDataCollector(string $server, array $serverConfig): ServerDataCollectorInterface
    {
        $environment = $this->appConfig->environment->env;

        if ($environment === 'demo') {
            $responseNormalizer = new NodeResponseNormalizer($this->logger);

            return new MockServerDataCollector(
                $this->logger,
                $this->ttlResolver,
                $responseNormalizer,
                $server,
                $serverConfig
            );
        }
        if (!isset($serverConfig['api_key'], $serverConfig['api_url'])) {
            throw new InvalidArgumentException("Server {$server} missing required config");
        }
        // Extract security flags with defaults
        $allowHttp = TypeConverter::toBool($serverConfig['allow_http'] ?? null) ?? false;
        $suppressHttpWarning = TypeConverter::toBool($serverConfig['suppress_http_warning'] ?? null) ?? false;

        $apiClient = new NodeApiClient(
            $this->logger,
            $serverConfig['api_url'],
            $server,
            $serverConfig['api_key'],
            10,
            true,
            $allowHttp,
            $suppressHttpWarning
        );
        $responseNormalizer = new NodeResponseNormalizer($this->logger);

        return new ServerDataCollector(
            $this->logger,
            $this->cacheStore,
            $this->cacheKeyFactory,
            $this->ttlResolver,
            $apiClient,
            $responseNormalizer
        );
    }

    /**
     * Fetches and renders a app's README.md as modal HTML content.
     *
     * @param bool $forceRefresh Whether to bypass cache
     *
     * @return Response Rendered modal HTML response
     */
    public function renderAppReadme(
        string $server,
        string $app,
        bool $forceRefresh = false
    ): Response {
        return $this->executeWithErrorHandling(
            function () use ($server, $app, $forceRefresh) {
                if (!isset($this->serverDataCollectors[$server])) {
                    return $this->handleHtmlNotFound("Server '{$server}'", 'README request');
                }
                $serverData = $this->serverDataCollectors[$server];

                $readmeResponse = $this->requireValidResponse(
                    $this->modalCacheService->fetchReadme($serverData, $app, $forceRefresh),
                    "README of {$app} on {$server}"
                );

                $markdownContent = $readmeResponse['result'] ?? '';
                $notice = is_array($readmeResponse['notice'] ?? null)
                    ? $readmeResponse['notice']
                    : null;

                $endpoints = [
                    'modal_app_readme_refresh' => $this->endpoints->url(
                        'modal_app_readme_refresh',
                        ['server' => $server, 'app' => $app]
                    ),
                ];

                $html = $this->modalRenderer->renderReadmeModal(
                    $markdownContent,
                    $server,
                    $app,
                    $readmeResponse['cache_age'] ?? 0,
                    $readmeResponse['cache_ttl'] ?? 0,
                    $endpoints,
                    $notice
                );

                return new HtmlResponse($html);
            },
            'Failed to load application README',
            "README for {$app} on {$server}"
        );
    }

    /**
     * Fetch and render application logs modal.
     *
     * @param bool $forceRefresh Whether to bypass cache
     *
     * @return Response Rendered modal HTML or JSON response
     */
    public function renderAppLogs(
        string $server,
        string $app,
        bool $forceRefresh = false
    ): Response {
        return $this->executeWithErrorHandling(
            function () use ($server, $app, $forceRefresh) {
                if (!isset($this->serverDataCollectors[$server])) {
                    return $this->handleHtmlNotFound("Server '{$server}'", 'Logs request');
                }
                $serverData = $this->serverDataCollectors[$server];

                $logsResponse = $this->modalCacheService->fetchLogs($serverData, $app, $forceRefresh);
                if ($this->monitoringDisabled($logsResponse)) {
                    return new HtmlResponse($this->modalRenderer->renderMonitoringDisabledModal($server, $app, 'Log analysis'));
                }
                $logsResponse = $this->requireValidResponse($logsResponse, "logs of {$app} on {$server}");

                $endpoints = [
                    'app_logs_json' => $this->endpoints->url('app_logs_json', [
                        'server' => $server,
                        'app' => $app,
                    ]),
                    'app_logs_status_json' => $this->endpoints->url('app_logs_status_json', [
                        'server' => $server,
                        'app' => $app,
                    ]),
                    'modal_app_logs_refresh' => $this->endpoints->url(
                        'modal_app_logs_refresh',
                        ['server' => $server, 'app' => $app]
                    ),
                ];

                $html = $this->modalRenderer->renderLogsModal(
                    $server,
                    $app,
                    $logsResponse['cache_age'] ?? 0,
                    $logsResponse['cache_ttl'] ?? 0,
                    $endpoints,
                    (int) ($logsResponse['result']['readout_limit'] ?? 0)
                );

                return new HtmlResponse($html);
            },
            'Failed to load application logs',
            "Logs for {$app} on {$server}"
        );
    }

    /**
     * Fetch and render application log-file metadata.
     *
     * @param bool $forceRefresh Whether to bypass cache
     *
     * @return Response Rendered modal HTML response
     */
    public function renderAppLogFiles(
        string $server,
        string $app,
        bool $forceRefresh = false
    ): Response {
        return $this->executeWithErrorHandling(
            function () use ($server, $app, $forceRefresh) {
                if (!isset($this->serverDataCollectors[$server])) {
                    return $this->handleHtmlNotFound("Server '{$server}'", 'Log files request');
                }

                $logsStatusResponse = $this->modalCacheService->fetchLogsStatus(
                    $this->serverDataCollectors[$server],
                    $app,
                    $forceRefresh
                );
                if ($this->monitoringDisabled($logsStatusResponse)) {
                    return new HtmlResponse($this->modalRenderer->renderMonitoringDisabledModal($server, $app, 'Log-size monitoring'));
                }
                $logsStatusResponse = $this->requireValidResponse($logsStatusResponse, "log files of {$app} on {$server}");
                $logFiles = is_array($logsStatusResponse['result'] ?? null)
                    ? $logsStatusResponse['result']
                    : [];
                $endpoints = [
                    'modal_app_log_files_refresh' => $this->endpoints->url(
                        'modal_app_log_files_refresh',
                        ['server' => $server, 'app' => $app]
                    ),
                ];

                $html = $this->modalRenderer->renderAppLogFilesModal(
                    $logFiles,
                    $server,
                    $app,
                    $logsStatusResponse['cache_age'] ?? 0,
                    $logsStatusResponse['cache_ttl'] ?? 0,
                    $endpoints
                );

                return new HtmlResponse($html);
            },
            'Failed to load application log files',
            "Log files for {$app} on {$server}"
        );
    }

    /**
     * Return application logs data as JSON response.
     *
     * @param string $server       Server identifier
     * @param string $app          Application code
     * @param bool   $forceRefresh Whether to bypass cache
     *
     * @return Response JSON response with log data
     */
    public function getAppLogsJson(string $server, string $app, bool $forceRefresh = false): Response
    {
        return $this->executeWithErrorHandling(
            function () use ($server, $app, $forceRefresh) {
                if (!isset($this->serverDataCollectors[$server])) {
                    return $this->handleJsonNotFound("Server '{$server}'", 'Logs JSON request');
                }
                $serverData = $this->serverDataCollectors[$server];

                $logsResponse = $this->modalCacheService->fetchLogs($serverData, $app, $forceRefresh);
                if ($this->monitoringDisabled($logsResponse)) {
                    return $this->monitoringDisabledJson();
                }
                $logsResponse = $this->requireValidResponse($logsResponse, "logs of {$app} on {$server}");

                $groupedLogs = $logsResponse['result']['entries'] ?? [];

                // Transform to flat structure with source field
                $flatLogs = $this->logTransformer->flattenGroupedLogs($groupedLogs);

                return new JsonResponse($flatLogs);
            },
            'Failed to load application logs',
            "Logs JSON for {$app} on {$server}"
        );
    }

    /**
     * Return log file metadata as JSON response.
     *
     * @param string $server       Server identifier
     * @param string $app          Application code
     * @param bool   $forceRefresh Whether to bypass cache
     *
     * @return Response JSON response with log file metadata
     */
    public function getAppLogsStatusJson(string $server, string $app, bool $forceRefresh = false): Response
    {
        return $this->executeWithErrorHandling(
            function () use ($server, $app, $forceRefresh) {
                if (!isset($this->serverDataCollectors[$server])) {
                    return $this->handleJsonNotFound("Server '{$server}'", 'Logs status JSON request');
                }
                $serverData = $this->serverDataCollectors[$server];

                $logsStatusResponse = $this->modalCacheService->fetchLogsStatus($serverData, $app, $forceRefresh);
                if ($this->monitoringDisabled($logsStatusResponse)) {
                    return $this->monitoringDisabledJson();
                }
                $logsStatusResponse = $this->requireValidResponse($logsStatusResponse, "logs status of {$app} on {$server}");

                $logsStatusData = $logsStatusResponse['result'] ?? [];

                return new JsonResponse($logsStatusData);
            },
            'Failed to load logs status',
            "Logs status JSON for {$app} on {$server}"
        );
    }

    /**
     * Fetch and render server information for a server.
     *
     * @param string $server       Server name
     * @param bool   $forceRefresh Whether to bypass cache
     *
     * @return Response Rendered modal HTML response
     */
    public function renderServerInfo(
        string $server,
        bool $forceRefresh = false
    ): Response {
        return $this->executeWithErrorHandling(
            function () use ($server, $forceRefresh) {
                if (!isset($this->serverDataCollectors[$server])) {
                    return $this->handleHtmlNotFound("Server '{$server}'", 'Server info request');
                }
                $serverData = $this->serverDataCollectors[$server];

                $serverInfoResponse = $this->requireValidResponse(
                    $this->modalCacheService->fetchServerInfo($serverData, $forceRefresh),
                    "server info for {$server}"
                );

                $serverInfo = !empty($serverInfoResponse['result'])
                    ? $serverInfoResponse['result']
                    : [];

                $endpoints = [
                    'modal_server_info_refresh' => $this->endpoints->url(
                        'modal_server_info_refresh',
                        ['server' => $server]
                    ),
                ];

                $html = $this->modalRenderer->renderServerInfoModal(
                    $serverInfo,
                    $server,
                    $serverInfoResponse['cache_age'] ?? 0,
                    $serverInfoResponse['cache_ttl'] ?? 0,
                    $endpoints
                );

                return new HtmlResponse($html);
            },
            'Failed to load server information',
            "Server info for {$server}"
        );
    }

    /**
     * Fetch and render the process list for a database.
     *
     * @return Response Rendered modal HTML response
     */
    public function renderDatabaseProcesses(
        string $server,
        string $database,
        bool $forceRefresh = false
    ): Response {
        return $this->executeWithErrorHandling(
            function () use ($server, $database, $forceRefresh) {
                if (!isset($this->serverDataCollectors[$server])) {
                    return $this->handleHtmlNotFound("Server '{$server}'", 'Database process request');
                }

                $databaseResponse = $this->requireValidResponse(
                    $this->modalCacheService->fetchServerDatabases(
                        $this->serverDataCollectors[$server],
                        $forceRefresh
                    ),
                    "database processes for {$database} on {$server}"
                );
                $databases = $databaseResponse['result']['databases'] ?? null;

                if (!is_array($databases) || !isset($databases[$database]) || !is_array($databases[$database])) {
                    return $this->handleHtmlNotFound("Database '{$database}'", "process request on {$server}");
                }

                $processList = $databases[$database]['process_list'] ?? [];
                $processList = is_array($processList) ? $processList : [];

                $endpoints = [
                    'modal_db_processes_refresh' => $this->endpoints->url(
                        'modal_db_processes_refresh',
                        ['server' => $server, 'database' => $database]
                    ),
                ];

                $html = $this->modalRenderer->renderDatabaseProcessesModal(
                    $processList,
                    $server,
                    $database,
                    $databaseResponse['cache_age'] ?? 0,
                    $databaseResponse['cache_ttl'] ?? 0,
                    $endpoints
                );

                return new HtmlResponse($html);
            },
            'Failed to load database processes',
            "Database processes for {$database} on {$server}"
        );
    }

    /**
     * Fetch and render server cronjobs for a server.
     *
     * @param string $server       Server name
     * @param bool   $forceRefresh Whether to bypass cache
     *
     * @return Response Rendered modal HTML response
     */
    public function renderServerCronjobs(
        string $server,
        bool $forceRefresh = false
    ): Response {
        return $this->executeWithErrorHandling(
            function () use ($server, $forceRefresh) {
                if (!isset($this->serverDataCollectors[$server])) {
                    return $this->handleHtmlNotFound("Server '{$server}'", 'Server cronjobs request');
                }
                $serverData = $this->serverDataCollectors[$server];

                $cronjobsResponse = $this->requireValidResponse(
                    $this->modalCacheService->fetchServerCronjobs($serverData, $forceRefresh),
                    "server cronjobs for {$server}"
                );

                $cronjobs = !empty($cronjobsResponse['result']) && is_array($cronjobsResponse['result'])
                    ? $cronjobsResponse['result']
                    : [];

                $endpoints = [
                    'modal_server_cronjobs_refresh' => $this->endpoints->url(
                        'modal_server_cronjobs_refresh',
                        ['server' => $server]
                    ),
                ];

                $html = $this->modalRenderer->renderServerCronjobsModal(
                    $cronjobs,
                    $server,
                    $cronjobsResponse['cache_age'] ?? 0,
                    $cronjobsResponse['cache_ttl'] ?? 0,
                    $endpoints
                );

                return new HtmlResponse($html);
            },
            'Failed to load server cronjobs',
            "Server cronjobs for {$server}"
        );
    }

    private function monitoringDisabled(array|string|null $response): bool
    {
        $error = is_array($response) && is_array($response['error'] ?? null)
            ? $response['error']
            : [];

        return ($error['status_code'] ?? null) === 409
            && ($error['message'] ?? null) === 'Monitoring disabled';
    }

    private function monitoringDisabledJson(): JsonResponse
    {
        return new JsonResponse(['error' => 'Monitoring disabled', 'message' => 'Monitoring disabled'], 409);
    }

    /**
     * Validate a collector response: non-null and free of node-reported errors.
     *
     * @param null|array<string,mixed>|string $response
     *
     * @return array<string,mixed> The validated response
     *
     * @throws Exception When the response is missing or carries a node error
     */
    private function requireValidResponse(array|string|null $response, string $context): array
    {
        if (!is_array($response)) {
            throw new Exception("Failed to fetch {$context} via API.");
        }

        $error = $response['error'] ?? null;
        if (is_array($error) && is_string($error['message'] ?? null)) {
            throw new Exception($error['message']);
        }

        return $response;
    }
}
