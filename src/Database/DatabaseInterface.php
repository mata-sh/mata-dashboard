<?php

declare(strict_types=1);

namespace Mata\Database;

/**
 * Common contract for database implementations used by the application.
 *
 * Allows swapping the concrete database connection while keeping consumers
 * agnostic of the actual implementation (e.g. real PDO-backed or null object).
 */
interface DatabaseInterface
{
    /**
     * Execute a statement that returns rows (SELECT, SHOW, WITH ...).
     *
     * @param string                  $sql    SQL statement to execute
     * @param array<int|string,mixed> $params Parameters for prepared statement
     *
     * @return array<int,array<string,mixed>> Result rows
     */
    public function runSelect(string $sql, array $params = []): array;

    /**
     * Execute an INSERT whose auto-increment ID the caller needs.
     *
     * @param string                  $sql    SQL statement to execute
     * @param array<int|string,mixed> $params Parameters for prepared statement
     *
     * @return int Last insert ID
     */
    public function runInsert(string $sql, array $params = []): int;

    /**
     * Execute a write statement (UPDATE, DELETE, upsert, ...).
     *
     * @param string                  $sql    SQL statement to execute
     * @param array<int|string,mixed> $params Parameters for prepared statement
     *
     * @return int Affected row count
     */
    public function runStatement(string $sql, array $params = []): int;

    /**
     * Begin a database transaction.
     */
    public function beginTransaction(): void;

    /**
     * Commit the active transaction.
     */
    public function commit(): void;

    /**
     * Rollback the active transaction.
     */
    public function rollback(): void;
}
