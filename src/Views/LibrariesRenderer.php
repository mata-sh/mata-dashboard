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
                'libraries_json' => $endpoints->url('libraries_json'),
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
     * @return array{data:array<int,array<string,string>>,errors:array<int,array{server:string,message:string}>}
     */
    public function prepareJsonData(array $serversData, array $serversConfig): array
    {
        $libraryRows = [];
        $errors = [];

        foreach ($serversConfig as $server => $serverConfig) {
            try {
                if (!isset($serversData[$server])) {
                    $errors[] = ['server' => $server, 'message' => 'Libraries service is unavailable.'];

                    continue;
                }
                $appsLibs = $serversData[$server]->getAppsLibraries();
                $endpointError = is_array($appsLibs)
                    && is_array($appsLibs['error'] ?? null)
                    && is_string($appsLibs['error']['message'] ?? null)
                    ? $appsLibs['error']['message']
                    : null;
                if ($endpointError !== null) {
                    $errors[] = ['server' => $server, 'message' => $endpointError];

                    continue;
                }
                if (!is_array($appsLibs['result'] ?? null)) {
                    $errors[] = ['server' => $server, 'message' => 'Failed to fetch libraries data via API.'];

                    continue;
                }
                foreach ($appsLibs['result'] as $appName => $libArray) {
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
            } catch (Exception $e) {
                $this->logError($e->getMessage(), 'LibrariesData', [
                    'server' => $server,
                ]);

                $errors[] = ['server' => $server, 'message' => $e->getMessage()];
            }
        }


        return ['data' => $libraryRows, 'errors' => $errors];
    }
}
