<?php

declare(strict_types=1);

namespace Mata\Factory;

use Mata\Core\DatabaseInterface;
use Mata\Models\ServerMetrics\MockServerMetricsQuery;
use Mata\Models\ServerMetrics\ServerMetricsQuery;
use Mata\Models\ServerMetrics\ServerMetricsQueryInterface;
use Mata\Services\Foundation\IdResolver;

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
