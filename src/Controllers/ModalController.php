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
use Mata\Services\Cache\CacheTypeResolver;
use Mata\Services\Cache\ModalCacheService;
use Mata\Services\Collect\MockServerDataCollector;
use Mata\Services\Collect\ServerDataCollector;
use Mata\Services\Collect\ServerDataCollectorInterface;
use Mata\Services\Foundation\ApiClient;
use Mata\Services\Foundation\CacheManager;
use Mata\Services\Foundation\DataTransformers\ApiResponseTransformer;
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

    /** @var array<string,ServerDataCollectorInterface> */
    private array $serverDataCollectors = [];

    /** @var array<string,mixed> */
    private array $serversConfig;

    private ApplicationConfig $appConfig;

    /**
     * Initialize modal controller with dependencies.
     *
     * @param ApplicationConfig   $appConfig     Application configuration DTO
     * @param array<string,mixed> $serversConfig Server configuration array
     * @param CacheManager        $cacheManager  Cache management service with TTL configuration
     */
    public function __construct(
        App $app,
        Tools $tools,
        Environment $twig,
        Logger $logger,
        ApplicationConfig $appConfig,
        CacheManager $cacheManager,
        array $serversConfig
    ) {
        $this->tools = $tools;
        $this->logger = $logger;
        $this->appConfig = $appConfig;
        $this->cacheManager = $cacheManager;
        $this->serversConfig = $serversConfig;
        $cacheTypeResolver = new CacheTypeResolver();
        $this->modalCacheService = new ModalCacheService($cacheTypeResolver);

        // Initialize ModalRenderer
        $this->modalRenderer = new ModalRenderer($twig, $logger, $tools, $app);

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

                $html = $this->modalRenderer->renderReadmeModal(
                    $markdownContent,
                    $server,
                    $app,
                    $readmeResponse['cache_age'] ?? 0,
                    $readmeResponse['cache_ttl'] ?? 0
                );

                return new HtmlResponse($html);
            },
            'Failed to load application README',
            "README for {$app} on {$server}"
        );
    }

    /**
     * Fetch and render error log content for a app.
     *
     * @param bool $forceRefresh Whether to bypass cache
     *
     * @return Response Rendered modal HTML or JSON response
     */
    public function renderAppErrorlog(
        string $server,
        string $app,
        bool $forceRefresh = false
    ): Response {
        return $this->executeWithErrorHandling(
            function () use ($server, $app, $forceRefresh) {
                if (!isset($this->serverDataCollectors[$server])) {
                    return $this->handleNotFound("Server '{$server}'", 'Errorlog request');
                }
                $serverData = $this->serverDataCollectors[$server];

                $errorlogResponse = $this->modalCacheService->fetchErrorLog($serverData, $app, $forceRefresh);

                if ($errorlogResponse === null) {
                    throw new Exception("Failed to fetch errorlog of {$app} on {$server} via API");
                }

                $html = $this->modalRenderer->renderErrorlogModal(
                    $server,
                    $app,
                    $errorlogResponse['cache_age'] ?? 0,
                    $errorlogResponse['cache_ttl'] ?? 0
                );

                return new HtmlResponse($html);
            },
            'Failed to load application error log',
            "Error log for {$app} on {$server}"
        );
    }

    /**
     * Return error log data as JSON response.
     *
     * @param string $server       Server identifier
     * @param string $app          Application code
     * @param bool   $forceRefresh Whether to bypass cache
     *
     * @return Response JSON response with error log data
     */
    public function getAppErrorlogJson(string $server, string $app, bool $forceRefresh = false): Response
    {
        return $this->executeWithErrorHandling(
            function () use ($server, $app, $forceRefresh) {
                if (!isset($this->serverDataCollectors[$server])) {
                    return $this->handleNotFound("Server '{$server}'", 'Errorlog JSON request');
                }
                $serverData = $this->serverDataCollectors[$server];

                $errorlogResponse = $this->modalCacheService->fetchErrorLog($serverData, $app, $forceRefresh);

                if ($errorlogResponse === null) {
                    throw new Exception("Failed to fetch errorlog of {$app} on {$server} via API");
                }

                $errorlogData = $errorlogResponse['result'] ?? [];

                return new JsonResponse($errorlogData);
            },
            'Failed to load application error log',
            "Error log JSON for {$app} on {$server}"
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

                $html = $this->modalRenderer->renderServerInfoModal(
                    $serverInfo,
                    $server,
                    $serverInfoResponse['cache_age'] ?? 0,
                    $serverInfoResponse['cache_ttl'] ?? 0
                );

                return new HtmlResponse($html);
            },
            'Failed to load server information',
            "Server info for {$server}"
        );
    }
}
