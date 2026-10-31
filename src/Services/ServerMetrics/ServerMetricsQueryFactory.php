<?php

declare(strict_types=1);

namespace Mata\Services\ServerMetrics;

use Mata\Database\DatabaseInterface;
use Mata\Services\Identity\ServerApplicationIdResolver;

/**
 * Factory responsible for creating the appropriate server metrics query
 * implementation based on the current runtime environment.
 *
 * In demo mode we rely on {@see MockServerMetricsQuery} to provide deterministic
 * sample data, while non-demo environments use {@see ServerMetricsQuery} backed
 * by the database connection.
 */
final readonly class ServerMetricsQueryFactory
{
    public function __construct(
        private bool $demo,
        private DatabaseInterface $database,
        private ServerApplicationIdResolver $idResolver,
    ) {}

    public function create(): ServerMetricsQueryInterface
    {
        if ($this->demo) {
            return new MockServerMetricsQuery();
        }

        return new ServerMetricsQuery($this->database, $this->idResolver);
    }
}
