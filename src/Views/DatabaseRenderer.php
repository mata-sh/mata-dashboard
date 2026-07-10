<?php

declare(strict_types=1);

namespace Mata\Views;

use Exception;
use Mata\Core\App;
use Mata\Http\Routing\Endpoints;
use Mata\Services\Collect\ServerDataCollectorInterface;
use Mata\Utils\Tools;
use Monolog\Logger;
use Twig\Environment;

/**
 * Database rendering service.
 *
 * Handles database-related view rendering:
 * - Database container generation
 * - Individual database card rendering
 * - Database metrics visualization
 */
class DatabaseRenderer extends BaseRenderer
{
    public function __construct(
        Environment $twig,
        Logger $logger,
        Tools $tools,
        App $app,
        Endpoints $endpoints
    ) {
        parent::__construct($twig, $logger, $tools, $app, $endpoints);
    }

    /**
     * Render complete database container with wrapper template.
     *
     * @param array<string,ServerDataCollectorInterface> $serversData   Server API clients
     * @param array<string,mixed>                        $serversConfig Server configurations
     * @param bool                                       $forceRefresh  Force refresh bypassing cache
     *
     * @return string Generated HTML for complete database container
     */
    public function renderContainer(array $serversData, array $serversConfig, bool $forceRefresh = false): string
    {
        $databaseCardsHtml = $this->renderDatabaseCards($serversData, $serversConfig, $forceRefresh);

        return $this->twig->render('partials/containers/databases-container.html.twig', [
            'content' => [
                'database_container' => $databaseCardsHtml,
            ],
        ]);
    }

    /**
     * Render database cards without wrapper template.
     *
     * @param array<string,ServerDataCollectorInterface> $serversData   Server API clients
     * @param array<string,mixed>                        $serversConfig Server configurations
     * @param bool                                       $forceRefresh  Force refresh bypassing cache
     *
     * @return string Generated HTML for database cards
     */
    public function renderDatabaseCards(array $serversData, array $serversConfig, bool $forceRefresh = false): string
    {
        $databaseContainerHtml = '';

        foreach ($serversConfig as $server => $serverConfig) {
            $serverId = $this->generateHtmlId($server, 'server');

            try {
                if (!isset($serversData[$server])) {
                    throw new Exception("Invalid server: {$server}");
                }

                $serverData = $serversData[$server];
                [$databaseData, $endpointError] = $this->extractDatabaseResponse($serverData->getServerDatabases());

                $renderData = $this->buildDatabaseCardData(
                    $databaseData,
                    $serverConfig,
                    $serverId,
                    $endpointError,
                );

                $databaseContainerHtml .= $this->twig->render(
                    'partials/cards/database-card.html.twig',
                    $renderData
                );
            } catch (Exception $e) {
                $this->logError($e->getMessage(), 'DatabaseCard', [
                    'server' => $server,
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);

                $databaseContainerHtml .= $this->twig->render(
                    'partials/cards/database-card.html.twig',
                    $this->buildDatabaseCardData([], $serverConfig, $serverId, $e->getMessage())
                );
            }
        }

        return $databaseContainerHtml;
    }

    /**
     * Render individual database card for a specific server.
     *
     * @param string                       $server              Server name
     * @param array<string,mixed>          $serverConfig        Server configuration
     * @param ServerDataCollectorInterface $serverDataCollector Server API client
     *
     * @return string Generated HTML for database card
     *
     * @throws Exception When database data cannot be fetched
     */
    public function renderCard(string $server, array $serverConfig, ServerDataCollectorInterface $serverDataCollector): string
    {
        $serverId = $this->generateHtmlId($server, 'server');

        [$databaseData, $endpointError] = $this->extractDatabaseResponse($serverDataCollector->getServerDatabases());

        $renderData = $this->buildDatabaseCardData(
            $databaseData,
            $serverConfig,
            $serverId,
            $endpointError,
        );

        return $this->twig->render('partials/cards/database-card.html.twig', $renderData);
    }

    /**
     * Build template data for database card.
     *
     * @param array<string,mixed> $databaseData Database data from API
     * @param array<string,mixed> $serverConfig Server configuration
     * @param string              $serverId     HTML ID for server
     *
     * @return array<string,mixed> Template data array
     */
    public function buildDatabaseCardData(
        array $databaseData,
        array $serverConfig,
        string $serverId,
        ?string $errorMessage = null,
    ): array {
        return [
            'server' => [
                'title' => $serverConfig['title'],
                'primary_color' => $serverConfig['primary_color'],
                'id' => $serverId,
            ],
            'icons' => $this->getCommonIcons() + [
                'refresh_server' => $this->tools->icon('refresh-ccw', 12),
                'refresh_stats' => $this->tools->icon('refresh-ccw', 12),
            ],
            'system' => [
                'processes_sql' => $databaseData['processes']['sql'] ?? null,
            ],
            'ui' => [
                'error_message' => $errorMessage,
            ],
        ];
    }

    /**
     * Extract database payload and endpoint error from a collector response.
     *
     * @param null|array<string,mixed>|string $response Raw collector response
     *
     * @return array{0: array<string,mixed>, 1: null|string} [$databaseData, $endpointError]
     *
     * @throws Exception When the response carries neither data nor an error
     */
    private function extractDatabaseResponse(array|string|null $response): array
    {
        $endpointError = is_array($response)
            && is_array($response['error'] ?? null)
            && is_string($response['error']['message'] ?? null)
            ? $response['error']['message']
            : null;

        $databaseData = is_array($response) && is_array($response['result'] ?? null)
            ? $response['result']
            : [];

        if ($endpointError === null && $databaseData === []) {
            throw new Exception('Failed to fetch databases data via API.');
        }

        return [$databaseData, $endpointError];
    }
}
