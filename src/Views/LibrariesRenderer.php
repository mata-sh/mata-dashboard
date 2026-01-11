<?php

declare(strict_types=1);

namespace Mata\Views;

use Exception;
use Mata\Http\Routing\Endpoints;
use Mata\Services\Collect\ServerDataCollectorInterface;

/**
 * Libraries rendering service.
 *
 * Handles libraries-related view rendering:
 * - Libraries container generation
 * - Library listings and tables
 * - JSON data formatting for libraries
 */
class LibrariesRenderer extends BaseRenderer
{
    /**
     * Render complete libraries container with wrapper template.
     *
     * @param array<string,mixed> $serversConfig Server configurations
     *
     * @return string Generated HTML for complete libraries container
     */
    public function renderContainer(Endpoints $endpoints, array $serversConfig): string
    {
        return $this->twig->render('partials/containers/libraries-container.html.twig', [
            'endpoints' => [
                'libraries_json' => $endpoints->get('libraries_json'),
            ],
            'filters' => [
                'servers' => $this->buildServerDropdownOptions($serversConfig),
            ],
        ]);
    }

    // Todo: not sure about having this logic inside of the renderer
    /**
     * Prepare libraries data for JSON API responses.
     *
     * @param array<string,ServerDataCollectorInterface> $serversData   Server API clients
     * @param array<string,mixed>                        $serversConfig Server configurations
     *
     * @return array<int,array<string,string>> Formatted library data for JSON response
     */
    public function prepareJsonData(array $serversData, array $serversConfig): array
    {
        $libraryData = [];
        $libraryRows = [];

        foreach ($serversConfig as $server => $serverConfig) {
            try {
                if (!isset($serversData[$server])) {
                    continue;
                }
                $appsLibs = $serversData[$server]->getAppsLibraries();
                if ($appsLibs) {
                    $libraryData[$server] = $appsLibs['result'];
                }
            } catch (Exception $e) {
                $this->logError($e->getMessage(), 'LibrariesData', [
                    'server' => $server,
                ]);

                continue;
            }
        }

        foreach ($libraryData as $server => $apps) {
            foreach ($apps as $appName => $libArray) {
                foreach ($libArray as $lib) {
                    $libraryRows[] = [
                        'server' => $server,
                        'app' => $appName,
                        'name' => $lib['name'] ?? '',
                        'version' => $lib['version'] ?? '',
                        'type' => $lib['type'] ?? '',
                        'description' => $lib['description'] ?? '',
                    ];
                }
            }
        }

        return $libraryRows;
    }
}
