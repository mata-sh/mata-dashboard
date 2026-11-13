<?php

declare(strict_types=1);

namespace Mata\Cli;

use Mata\Config\Dto\ApplicationConfig;
use Mata\Config\Monitoring\Section\ServerConfig;
use Mata\Database\PdoDatabase;
use Mata\Models\Sync\ConfigSyncRepository;
use Mata\Services\Identity\ServerApplicationIdResolver;
use Mata\Services\ServerMetrics\MockServerMetricsQuery;
use Mata\Services\ServerMetrics\ServerMetricsQuery;
use Mata\Services\ServerMetrics\ServerMetricsQueryInterface;

/** The server metrics the dashboard stored, as metrics series and evidence read them. */
final class StoredMetrics
{
    /**
     * Generated in demo mode, without connecting to the database.
     *
     * @param array<string,ServerConfig> $servers servers.json, for the server names
     */
    public static function query(ApplicationConfig $config, array $servers): ServerMetricsQueryInterface
    {
        if ($config->environment->isDemo()) {
            return new MockServerMetricsQuery();
        }
        $db = new PdoDatabase($config->database);
        $idResolver = new ServerApplicationIdResolver(new ConfigSyncRepository($db));
        $idResolver->refreshFromRepository($servers);

        return new ServerMetricsQuery($db, $idResolver);
    }
}
