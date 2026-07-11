<?php

declare(strict_types=1);

namespace Mata\Services\View;

use Exception;
use Mata\Services\Audit\AuditRowEnricher;
use Mata\Services\Collect\ServerDataCollectorInterface;
use Psr\Log\LoggerInterface;

final class LibrariesViewBuilder
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly AuditRowEnricher $auditRowEnricher,
    ) {}

    /**
     * @param array<string,ServerDataCollectorInterface> $serversData   Server API clients
     * @param array<string,mixed>                        $serversConfig Server configurations
     *
     * @return array{data:array<int,array<string,mixed>>,errors:array<int,array{server:string,message:string}>}
     */
    public function buildLibrariesViewData(array $serversData, array $serversConfig): array
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
                $appAudits = [];
                foreach ($appsLibs['result'] as $appName => $libArray) {
                    $app = (string) $appName;
                    if (!array_key_exists($app, $appAudits)) {
                        $appAudits[$app] = $this->auditRowEnricher->appAudit($serversData[$server], $app);
                    }

                    foreach ($libArray as $lib) {
                        $row = [
                            'server' => $server,
                            'app' => $app,
                            'name' => $lib['name'] ?? '',
                            'version' => $lib['version'] ?? '',
                            'type' => $lib['type'] ?? '',
                            'description' => $lib['description'] ?? '',
                            'audited' => $appAudits[$app]['audited'],
                        ];
                        $libraryRows[] = $this->auditRowEnricher->enrichRow($row, $appAudits[$app]);
                    }
                }
            } catch (Exception $e) {
                $this->logger->error("Rendering error in LibrariesData: {$e->getMessage()}", array_merge([
                    'server' => $server,
                ], [
                    'component' => 'LibrariesData',
                ]));

                $errors[] = ['server' => $server, 'message' => $e->getMessage()];
            }
        }

        return ['data' => $libraryRows, 'errors' => $errors];
    }
}
