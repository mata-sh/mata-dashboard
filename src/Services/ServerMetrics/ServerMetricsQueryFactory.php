<?php

declare(strict_types=1);

namespace Mata\Services\ServerMetrics;

use Mata\Core\DatabaseInterface;
use Mata\Persistence\Identity\ServerApplicationIdResolver;

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
    private ServerApplicationIdResolver $idResolver;

    public function __construct(
        string $environment,
        DatabaseInterface $database,
        ServerApplicationIdResolver $idResolver
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
