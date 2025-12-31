<?php

declare(strict_types=1);

namespace Watcher\Views;

use Exception;
use Watcher\Services\Collect\ServerDataCollectorInterface;

/**
 * Cronjob rendering service.
 *
 * Handles cronjob-related view rendering:
 * - Cronjob container generation
 * - Individual cronjob status rendering
 * - Cronjob monitoring visualization
 */
class CronjobRenderer extends BaseRenderer
{
    /**
     * Render complete cronjobs container with wrapper template.
     *
     * @param array<string,ServerDataCollectorInterface> $serversData   Server API clients
     * @param array<string,mixed>                        $serversConfig Server configurations
     * @param bool                                       $forceRefresh  Force refresh bypassing cache
     *
     * @return string Generated HTML for complete cronjobs container
     */
    public function renderContainer(array $serversData, array $serversConfig, bool $forceRefresh = false): string
    {
        $cronjobsCardsHtml = $this->renderCronjobCards($serversData, $serversConfig, $forceRefresh);

        return $this->twig->render('partials/containers/cronjobs-container.html.twig', [
            'content' => [
                'cronjobs_container' => $cronjobsCardsHtml,
            ],
        ]);
    }

    /**
     * Render cronjob cards without wrapper template.
     *
     * @param array<string,ServerDataCollectorInterface> $serversData   Server API clients
     * @param array<string,mixed>                        $serversConfig Server configurations
     * @param bool                                       $forceRefresh  Force refresh bypassing cache
     *
     * @return string Generated HTML for cronjob cards
     */
    public function renderCronjobCards(array $serversData, array $serversConfig, bool $forceRefresh = false): string
    {
        // TODO: Implement cronjobs HTML generation
        // This would iterate through servers, fetch cronjob data, and render cronjob cards
        return 'CronJobList';
    }

    /**
     * Render individual cronjob card for a specific server.
     *
     * @param string                       $server              Server name
     * @param array<string,mixed>          $serverConfig        Server configuration
     * @param ServerDataCollectorInterface $serverDataCollector Server API client
     *
     * @return string Generated HTML for cronjob card
     *
     * @throws Exception When cronjob data cannot be fetched
     */
    public function renderCard(string $server, array $serverConfig, ServerDataCollectorInterface $serverDataCollector): string
    {
        $serverId = $this->generateHtmlId($server, 'server');

        // TODO: Fetch cronjob data and build template data
        // This would prepare cronjob data for template rendering

        return '';
    }

    /**
     * Render cronjob status table.
     *
     * @param array<string,mixed> $cronjobData Array of cronjob information
     *
     * @return string HTML formatted cronjob status table
     */
    public function renderStatusTable(array $cronjobData): string
    {
        $htmlRow = '';
        foreach ($cronjobData as $job) {
            $name = $this->escape($job['name'] ?? '');
            $status = $this->escape($job['status'] ?? '');
            $lastRun = $this->escape($job['last_run'] ?? '');
            $nextRun = $this->escape($job['next_run'] ?? '');

            $statusClass = match ($job['status'] ?? '') {
                'running' => 'status-running',
                'failed' => 'status-failed',
                'success' => 'status-success',
                default => 'status-unknown'
            };

            $htmlRow .= <<<HTML
                <tr>
                    <td>{$name}</td>
                    <td><span class="{$statusClass}">{$status}</span></td>
                    <td>{$lastRun}</td>
                    <td>{$nextRun}</td>
                </tr>
        HTML;
        }

        return <<<HTML
            <table border="1" cellpadding="5" cellspacing="0">
                <thead>
                    <tr>
                        <th>Job Name</th>
                        <th>Status</th>
                        <th>Last Run</th>
                        <th>Next Run</th>
                    </tr>
                </thead>
                <tbody>        
                    {$htmlRow}
                </tbody>
            </table>
        HTML;
    }
}
