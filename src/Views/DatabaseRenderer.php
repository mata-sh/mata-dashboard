<?php

declare(strict_types=1);

namespace Mata\Views;

use Exception;
use Mata\Core\App;
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
    ) {
        parent::__construct($twig, $logger, $tools, $app);
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
                $response = $serverData->getServerDatabases();
                $databaseData = is_array($response) && isset($response['result']) ? $response['result'] : null;

                if ($databaseData === null) {
                    throw new Exception('Failed to fetch databases data via API.');
                }

                // Build template data using own method
                $renderData = $this->buildDatabaseCardData(
                    $databaseData,
                    $serverConfig,
                    $serverId
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

                // Skip failed servers
                continue;
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

        $response = $serverDataCollector->getServerDatabases();
        $databaseData = is_array($response) && isset($response['result']) ? $response['result'] : null;

        if ($databaseData === null) {
            throw new Exception('Failed to fetch databases data via API.');
        }

        // Build template data using own method
        $renderData = $this->buildDatabaseCardData(
            $databaseData,
            $serverConfig,
            $serverId
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
        string $serverId
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
                'processes_sql' => $databaseData['processes']['sql'] ?? 0,
            ],
        ];
    }
}
