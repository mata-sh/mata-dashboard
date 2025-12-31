<?php

declare(strict_types=1);

namespace Watcher\Factory;

use Watcher\Core\DatabaseInterface;
use Watcher\Models\ServerMetrics\MockServerMetricsQuery;
use Watcher\Models\ServerMetrics\ServerMetricsQuery;
use Watcher\Models\ServerMetrics\ServerMetricsQueryInterface;
use Watcher\Services\Foundation\IdResolver;

/**
 * Factory responsible for creating the appropriate server metrics query
 * implementation based on the current runtime environment.
 *
 * In demo mode we rely on {@see MockServerMetricsQuery} to provide deterministic
 * sample data, while non-demo environments use {@see ServerMetricsQuery} backed
 * by the database connection.
 */
final class ServerMetricsQueryFactory
{
    private string $environment;
    private DatabaseInterface $database;
    private IdResolver $idResolver;

    public function __construct(
        string $environment,
        DatabaseInterface $database,
        IdResolver $idResolver
    ) {
        $this->environment = strtolower(trim($environment));
        $this->database = $database;
        $this->idResolver = $idResolver;
    }

    public function create(): ServerMetricsQueryInterface
    {
        if ($this->environment === 'demo') {
            return new MockServerMetricsQuery();
        }

        return new ServerMetricsQuery($this->database, $this->idResolver);
    }
}
