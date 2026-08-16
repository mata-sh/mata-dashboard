<?php

declare(strict_types=1);

namespace Mata\Bootstrap;

use Closure;
use Exception;
use Mata\Config\DatabaseIntegrityChecker;
use Mata\Config\Dto\DatabaseConfig;
use Mata\Database\DatabaseInterface;
use Mata\Database\NullDatabase;
use Mata\Database\PdoDatabase;
use Psr\Log\LoggerInterface;
use Throwable;

final class DatabaseBootstrap
{
    /** @var Closure(DatabaseConfig): DatabaseInterface */
    private readonly Closure $databaseFactory;

    /** @var Closure(DatabaseInterface): DatabaseIntegrityChecker */
    private readonly Closure $integrityCheckerFactory;

    public function __construct(
        private readonly DatabaseConfig $config,
        private readonly LoggerInterface $logger,
        ?callable $databaseFactory = null,
        ?callable $integrityCheckerFactory = null,
    ) {
        $this->databaseFactory = $databaseFactory === null
            ? static fn (DatabaseConfig $config): DatabaseInterface => new PdoDatabase($config)
            : Closure::fromCallable($databaseFactory);
        $this->integrityCheckerFactory = $integrityCheckerFactory === null
            ? static fn (DatabaseInterface $database): DatabaseIntegrityChecker => new DatabaseIntegrityChecker($database)
            : Closure::fromCallable($integrityCheckerFactory);
    }

    public function initialize(): DatabaseInterface
    {
        try {
            $database = ($this->databaseFactory)($this->config);

            $databaseProbe = ($this->integrityCheckerFactory)($database);

            try {
                $databaseProbe->assertReady();
            } catch (Throwable $probeException) {
                $this->logger->error('Database quick probe failed; falling back to NullDatabase. ' . $probeException->getMessage());
                $this->logger->debug('Database quick probe failure details', ['exception' => $probeException]);
                $database = new NullDatabase($this->logger);
            }
        } catch (Exception $e) {
            $this->logger->error('Failed to initialize DB Connection: ' . $e->getMessage());
            $database = new NullDatabase($this->logger);
        }

        return $database;
    }
}
