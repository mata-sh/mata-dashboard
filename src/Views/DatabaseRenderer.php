<?php

declare(strict_types=1);

namespace Mata\Views;

use Exception;
use Mata\Services\Cache\CacheType;
use Mata\Services\Collect\ServerDataCollectorInterface;

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

                $collector = $serversData[$server];
                $renderData = $this->buildDatabaseCardData(
                    $collector->getDatabaseOverview($forceRefresh ? CacheType::None : CacheType::Databases),
                    $this->extractSqlProcessCount($collector->getServerSystemData()),
                    $serverConfig,
                    $serverId,
                    $server,
                );
            } catch (Exception $e) {
                $this->logError($e->getMessage(), 'DatabaseCard', [
                    'server' => $server,
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);

                $renderData = $this->buildDatabaseCardData(null, null, $serverConfig, $serverId, $server, $e->getMessage());
            }

            $databaseContainerHtml .= $this->twig->render('partials/cards/database-card.html.twig', $renderData);
        }

        return $databaseContainerHtml;
    }

    /**
     * Build template data for one server's database card.
     *
     * The overview describes one database server: status numbers are shown once per
     * server, and monitored databases are only listed with their process-list links.
     * A 404 from the overview means the node has database monitoring turned off.
     *
     * @param null|array<string,mixed> $overviewResponse Collector response for `databases/overview`
     * @param array<string,mixed>      $serverConfig     Server configuration
     *
     * @return array<string,mixed> Template data array
     */
    public function buildDatabaseCardData(
        ?array $overviewResponse,
        ?int $sqlProcessCount,
        array $serverConfig,
        string $serverId,
        string $server,
        ?string $errorMessage = null,
    ): array {
        $error = $overviewResponse['error'] ?? null;
        $overview = $overviewResponse['result'] ?? null;
        $notConfigured = is_array($error) && ($error['status_code'] ?? null) === 404;

        if ($errorMessage === null && !$notConfigured) {
            $errorMessage = match (true) {
                is_array($error) && is_string($error['message'] ?? null) => $error['message'],
                !is_array($overview) => 'Failed to fetch database overview via API.',
                default => null,
            };
        }

        $cacheAge = $overviewResponse['cache_age'] ?? null;
        $cacheTtl = $overviewResponse['cache_ttl'] ?? null;

        return [
            'server' => [
                'title' => $serverConfig['title'],
                'primary_color' => $serverConfig['primary_color'] ?? '#fefefe',
                'id' => $serverId,
                'cache_age' => is_int($cacheAge) ? $this->tools->formatDuration($cacheAge) : null,
                'cache_ttl' => is_int($cacheTtl) ? $this->tools->formatDuration($cacheTtl) : null,
                'refresh_url' => $this->endpoints->url('refresh_database', ['server' => $server]),
            ],
            'icons' => $this->getCommonIcons(),
            'system' => [
                'processes_sql' => $sqlProcessCount,
            ],
            'not_configured' => $notConfigured,
            'overview' => is_array($overview) ? $this->buildOverview($overview, $server) : null,
            'ui' => [
                'error_message' => $errorMessage,
            ],
        ];
    }

    /**
     * @param array<string,mixed> $overview Normalized `databases/overview` result
     *
     * @return array<string,mixed> Display-ready server status and monitored databases
     */
    private function buildOverview(array $overview, string $server): array
    {
        /** @var array<string,null|int> $status */
        $status = $overview['status'];

        /** @var list<array{name:string,description:string}> $databases */
        $databases = $overview['databases'];

        $details = [];
        foreach (['max_used_connections' => 'Peak', 'refused_connections' => 'Refused', 'aborted_connects' => 'Aborted'] as $field => $label) {
            if ($status[$field] !== null) {
                $details[] = $label . ' ' . $this->formatNumber($status[$field]);
            }
        }

        return [
            'engine' => $overview['engine'],
            'uptime' => $this->tools->formatDuration((int) $status['uptime_seconds']),
            'connections' => $this->formatNumber((int) $status['connections']),
            'max_connections' => $this->formatNumber((int) $status['max_connections']),
            'running' => $this->formatNumber((int) $status['running']),
            'details' => implode(' · ', $details),
            'process_modal_url' => $this->endpoints->url('modal_db_server_processes_render', ['server' => $server]),
            'databases' => array_map(
                fn (array $database): array => [
                    'name' => $database['name'],
                    'description' => $database['description'],
                    'process_modal_url' => $this->endpoints->url('modal_db_processes_render', [
                        'server' => $server,
                        'database' => $database['name'],
                    ]),
                ],
                $databases,
            ),
        ];
    }

    /**
     * Read the OS-level SQL process count from a `server/data` collector response.
     *
     * @param null|array<string,mixed> $systemResponse
     */
    private function extractSqlProcessCount(?array $systemResponse): ?int
    {
        $sql = $systemResponse['result']['processes']['sql'] ?? null;

        return is_int($sql) ? $sql : null;
    }
}
