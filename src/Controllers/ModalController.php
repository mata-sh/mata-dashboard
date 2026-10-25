<?php

declare(strict_types=1);

namespace Mata\Controllers;

use Exception;
use Mata\Core\App;
use Mata\Http\Request;
use Mata\Http\Responses\HtmlResponse;
use Mata\Http\Responses\JsonResponse;
use Mata\Http\Responses\Response;
use Mata\Http\Routing\Endpoints;
use Mata\Services\Cache\CacheTypeResolver;
use Mata\Services\Cache\ModalCacheService;
use Mata\Services\Collect\ServerDataCollectorInterface;
use Mata\Services\DataTransformers\LogDataTransformer;
use Mata\Utils\Tools;
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
    private ModalRenderer $modalRenderer;
    private ModalCacheService $modalCacheService;
    private LogDataTransformer $logTransformer;
    private Endpoints $endpoints;

    /** @var array<string,ServerDataCollectorInterface> */
    private array $serverDataCollectors;

    /**
     * Initialize modal controller with dependencies.
     *
     * @param LogDataTransformer                         $logTransformer       Log data transformation service
     * @param Endpoints                                  $endpoints            Route endpoints for URL generation
     * @param array<string,ServerDataCollectorInterface> $serverDataCollectors Collectors built at bootstrap, keyed by server
     */
    public function __construct(
        App $app,
        Tools $tools,
        Environment $twig,
        Logger $logger,
        LogDataTransformer $logTransformer,
        Endpoints $endpoints,
        array $serverDataCollectors,
        Request $request,
    ) {
        parent::__construct($request);
        $this->logger = $logger;
        $this->logTransformer = $logTransformer;
        $this->endpoints = $endpoints;
        $this->serverDataCollectors = $serverDataCollectors;
        $cacheTypeResolver = new CacheTypeResolver();
        $this->modalCacheService = new ModalCacheService($cacheTypeResolver);

        // Initialize ModalRenderer
        $this->modalRenderer = new ModalRenderer($twig, $logger, $tools, $app, $endpoints);
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

                $readmeResponse = $this->modalCacheService->fetchReadme($serverData, $app, $forceRefresh);
                $nodeError = $this->nodeErrorMessage($readmeResponse);
                if ($nodeError !== null) {
                    return $this->errorModal('README.md', $app, $nodeError, 502);
                }
                $readmeResponse = $this->requireValidResponse($readmeResponse, "README of {$app} on {$server}");

                $markdownContent = $readmeResponse['result'] ?? '';
                $notice = $this->extractNotice($readmeResponse);

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
                $nodeError = $this->nodeErrorMessage($logsResponse);
                if ($nodeError !== null) {
                    return $this->errorModal('Logs', $app, $nodeError, 502);
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
                $nodeError = $this->nodeErrorMessage($logsStatusResponse);
                if ($nodeError !== null) {
                    return $this->errorModal('Error Log Files', $app, $nodeError, 502);
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

                $serverInfoResponse = $this->modalCacheService->fetchServerInfo($serverData, $forceRefresh);
                $nodeError = $this->nodeErrorMessage($serverInfoResponse);
                if ($nodeError !== null) {
                    return $this->errorModal('Server Info', $server, $nodeError, 502);
                }
                $serverInfoResponse = $this->requireValidResponse($serverInfoResponse, "server info for {$server}");

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
     * Fetch and render the live process list of one connection, for the monitored database in
     * the `database` query parameter or all of them.
     *
     * @return Response Rendered modal HTML response
     */
    public function renderDatabaseProcesses(string $server, string $connection): Response
    {
        $database = $this->request->queryString('database');
        $target = $database === null ? "databases of connection '{$connection}' on {$server}" : "database '{$database}' of connection '{$connection}' on {$server}";
        $modalContext = $database === null ? "{$server} / {$connection}" : "{$server} / {$connection} / {$database}";

        return $this->executeWithErrorHandling(
            function () use ($server, $database, $connection, $target, $modalContext) {
                if (!isset($this->serverDataCollectors[$server])) {
                    return $this->handleHtmlNotFound("Server '{$server}'", 'Database process request');
                }

                $processesResponse = $this->serverDataCollectors[$server]->fetchDatabaseProcesses($connection, $database);
                // The node answers 404 for unknown databases and when monitoring is off.
                if (($processesResponse['error']['status_code'] ?? null) === 404) {
                    $this->logNotFound(ucfirst($target), 'Database process request');

                    return $this->errorModal('Database Processes', $modalContext, ucfirst($target) . ' not found', 404);
                }
                $nodeError = $this->nodeErrorMessage($processesResponse);
                if ($nodeError !== null) {
                    return $this->errorModal('Database Processes', $modalContext, $nodeError, ($processesResponse['error']['status_code'] ?? null) === 400 ? 400 : 502);
                }

                $processesResponse = $this->requireValidResponse($processesResponse, "process list for {$target}");
                $refreshEndpoint = $this->endpoints->url(
                    'modal_db_processes_render',
                    ['server' => $server, 'connection' => $connection],
                    $database === null ? [] : ['database' => $database],
                );

                return new HtmlResponse($this->modalRenderer->renderDatabaseProcessesModal(
                    $processesResponse['result'],
                    $server,
                    $database,
                    $refreshEndpoint,
                ));
            },
            'Failed to load database processes',
            "Database processes for {$target}"
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

                $cronjobsResponse = $this->modalCacheService->fetchServerCronjobs($serverData, $forceRefresh);
                $nodeError = $this->nodeErrorMessage($cronjobsResponse);
                if ($nodeError !== null) {
                    return $this->errorModal('Server Cronjobs', $server, $nodeError, 502);
                }
                $cronjobsResponse = $this->requireValidResponse($cronjobsResponse, "server cronjobs for {$server}");

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

    /**
     * Extract contextual, non-error information accompanying a successful node response.
     *
     * @param array<string,mixed> $response
     *
     * @return null|array{code:string,message:string}
     */
    private function extractNotice(array $response): ?array
    {
        $notice = $response['notice'] ?? null;

        if (
            !is_array($notice)
            || !is_string($notice['code'] ?? null)
            || !is_string($notice['message'] ?? null)
        ) {
            return null;
        }

        return [
            'code' => $notice['code'],
            'message' => $notice['message'],
        ];
    }

    /** @param null|array<string,mixed>|string $response */
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

    /**
     * The node-reported error of a collector response; the normalizer already made it user-safe.
     *
     * @param null|array<string,mixed>|string $response
     */
    private function nodeErrorMessage(array|string|null $response): ?string
    {
        $message = is_array($response) && is_array($response['error'] ?? null) ? $response['error']['message'] ?? null : null;

        return is_string($message) ? $message : null;
    }

    /**
     * Show why the modal content could not be loaded, inside the modal shell.
     */
    private function errorModal(string $title, ?string $context, string $message, int $statusCode): HtmlResponse
    {
        return new HtmlResponse($this->modalRenderer->renderErrorModal($title, $context, $message), $statusCode);
    }
}
