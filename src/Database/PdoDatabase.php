<?php

declare(strict_types=1);

namespace Mata\Database;

use Mata\Config\Dto\DatabaseConfig;
use PDO;
use PDOException;
use PDOStatement;

/**
 * MySQL database connection wrapper with prepared statements.
 *
 * Provides secure database operations with:
 * - PDO-based connection management
 * - Prepared statement support for SQL injection prevention
 * - Transaction support for data integrity
 * - Type-safe configuration via DatabaseConfig DTO
 * - Consistent error handling
 */
class PdoDatabase implements DatabaseInterface
{
    private PDO $pdo;

    /**
     * Initialize database connection with configuration.
     *
     * Creates PDO connection with secure defaults.
     *
     * @param DatabaseConfig $config Database configuration DTO
     *
     * @throws PDOException If database connection fails
     */
    public function __construct(DatabaseConfig $config, ?PDO $pdo = null)
    {
        if ($pdo === null) {
            $dsn = "mysql:host={$config->host}";
            if ($config->port !== '') {
                $dsn .= ";port={$config->port}";
            }
            $dsn .= ";dbname={$config->database};charset=utf8mb4";
            $pdo = new PDO($dsn, $config->username, $config->password);
        }

        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo = $pdo;
    }

    /**
     * @param array<int|string,mixed> $params Parameters for prepared statement
     *
     * @return array<int,array<string,mixed>>
     *
     * @throws PDOException If query execution fails
     */
    public function runSelect(string $sql, array $params = []): array
    {
        return $this->execute($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param array<int|string,mixed> $params Parameters for prepared statement
     *
     * @throws PDOException If query execution fails
     */
    public function runInsert(string $sql, array $params = []): int
    {
        $this->execute($sql, $params);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<int|string,mixed> $params Parameters for prepared statement
     *
     * @throws PDOException If query execution fails
     */
    public function runStatement(string $sql, array $params = []): int
    {
        return $this->execute($sql, $params)->rowCount();
    }

    /**
     * Begin database transaction for atomic operations.
     *
     * @throws PDOException If transaction cannot be started
     */
    public function beginTransaction(): void
    {
        $this->pdo->beginTransaction();
    }

    /**
     * Commit current database transaction.
     *
     * @throws PDOException If commit fails
     */
    public function commit(): void
    {
        $this->pdo->commit();
    }

    /**
     * Rollback current database transaction.
     *
     * @throws PDOException If rollback fails
     */
    public function rollback(): void
    {
        $this->pdo->rollBack();
    }

    /**
     * Get the underlying PDO connection instance.
     *
     * @return PDO Raw PDO connection for advanced operations
     */
    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    /** @param array<int|string,mixed> $params */
    private function execute(string $sql, array $params): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return $statement;
    }
}
