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
use Mata\Services\Cache\CacheTypeResolver;
use Mata\Services\Cache\ModalCacheService;
use Mata\Services\Collect\MockServerDataCollector;
use Mata\Services\Collect\ServerDataCollector;
use Mata\Services\Collect\ServerDataCollectorInterface;
use Mata\Services\DataTransformers\ApiResponseTransformer;
use Mata\Services\DataTransformers\LogDataTransformer;
use Mata\Services\Foundation\ApiClient;
use Mata\Services\Foundation\CacheManager;
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
    private Tools $tools;
    private CacheManager $cacheManager;
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
     * @param ApplicationConfig   $appConfig      Application configuration DTO
     * @param array<string,mixed> $serversConfig  Server configuration array
     * @param CacheManager        $cacheManager   Cache management service with TTL configuration
     * @param LogDataTransformer  $logTransformer Log data transformation service
     * @param Endpoints           $endpoints      Route endpoints for URL generation
     */
    public function __construct(
        App $app,
        Tools $tools,
        Environment $twig,
        Logger $logger,
        ApplicationConfig $appConfig,
        CacheManager $cacheManager,
        LogDataTransformer $logTransformer,
        Endpoints $endpoints,
        array $serversConfig
    ) {
        $this->tools = $tools;
        $this->logger = $logger;
        $this->appConfig = $appConfig;
        $this->cacheManager = $cacheManager;
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
            $dataTransformer = new ApiResponseTransformer($this->logger);

            return new MockServerDataCollector(
                $this->logger,
                $this->cacheManager,
                $dataTransformer,
                $server,
                $serverConfig
            );
        }
        if (!isset($serverConfig['api_key'], $serverConfig['api_url'])) {
            throw new InvalidArgumentException("Server {$server} missing required config");
        }
        $timestamp = time();
        $token = $this->tools->buildHmacAuthToken($server, $serverConfig['api_key'], $timestamp);

        // Extract security flags with defaults
        $allowHttp = TypeConverter::toBool($serverConfig['allow_http'] ?? null) ?? false;
        $suppressHttpWarning = TypeConverter::toBool($serverConfig['suppress_http_warning'] ?? null) ?? false;

        $apiClient = new ApiClient(
            $this->logger,
            $serverConfig['api_url'],
            $token,
            10,
            true,
            $allowHttp,
            $suppressHttpWarning
        );
        $dataTransformer = new ApiResponseTransformer($this->logger);

        return new ServerDataCollector(
            $this->logger,
            $this->cacheManager,
            $apiClient,
            $dataTransformer
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
                    return $this->handleNotFound("Server '{$server}'", 'README request');
                }
                $serverData = $this->serverDataCollectors[$server];

                $readmeResponse = $this->modalCacheService->fetchReadme($serverData, $app, $forceRefresh);

                if ($readmeResponse === null) {
                    throw new Exception("Failed to fetch README.md of {$app} on {$server} list via API.");
                }

                $markdownContent = !empty($readmeResponse['result'])
                    ? $readmeResponse['result']
                    : 'No Readme.md found.';

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
                    $endpoints
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
                    return $this->handleNotFound("Server '{$server}'", 'Logs request');
                }
                $serverData = $this->serverDataCollectors[$server];

                $logsResponse = $this->modalCacheService->fetchLogs($serverData, $app, $forceRefresh);

                if ($logsResponse === null) {
                    throw new Exception("Failed to fetch logs of {$app} on {$server} via API");
                }

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
                    $endpoints
                );

                return new HtmlResponse($html);
            },
            'Failed to load application logs',
            "Logs for {$app} on {$server}"
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
                    return $this->handleNotFound("Server '{$server}'", 'Logs JSON request');
                }
                $serverData = $this->serverDataCollectors[$server];

                // Fetch grouped logs from new API
                $logsResponse = $this->modalCacheService->fetchLogs($serverData, $app, $forceRefresh);

                if ($logsResponse === null) {
                    throw new Exception("Failed to fetch logs for {$app} on {$server}");
                }

                $groupedLogs = $logsResponse['result'] ?? [];

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
                    return $this->handleNotFound("Server '{$server}'", 'Logs status JSON request');
                }
                $serverData = $this->serverDataCollectors[$server];

                $logsStatusResponse = $this->modalCacheService->fetchLogsStatus($serverData, $app, $forceRefresh);

                if ($logsStatusResponse === null) {
                    throw new Exception("Failed to fetch logs status for {$app} on {$server}");
                }

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
                    return $this->handleNotFound("Server '{$server}'", 'Server info request');
                }
                $serverData = $this->serverDataCollectors[$server];

                $serverInfoResponse = $this->modalCacheService->fetchServerInfo($serverData, $forceRefresh);

                if ($serverInfoResponse === null) {
                    throw new Exception("Failed to fetch server info for {$server} via API");
                }

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
}
