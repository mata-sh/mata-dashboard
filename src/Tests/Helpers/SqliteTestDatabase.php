<?php

declare(strict_types=1);

namespace Mata\Tests\Helpers;

use Mata\Database\DatabaseInterface;
use PDO;
use PDOStatement;
use RuntimeException;

/**
 * In-memory SQLite implementation of DatabaseInterface for SQL-backed tests.
 *
 * Records executed queries and can inject forced failures for resilience
 * scenarios. Schema creation is the consuming test's responsibility.
 */
final class SqliteTestDatabase implements DatabaseInterface
{
    private PDO $pdo;

    /** @var array<int,array{sql:string,params:array<int|string,mixed>}> */
    private array $queryLog = [];

    /** @var array<int,string> */
    private array $failureNeedles = [];

    /** @var array<string,int> */
    private array $failureAttempts = [];

    /** @var array<string,int> */
    private array $queryAttempts = [];

    public function __construct()
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /** @return array<int,array{sql:string,params:array<int|string,mixed>}> */
    public function queryLog(): array
    {
        return $this->queryLog;
    }

    public function resetQueryLog(): void
    {
        $this->queryLog = [];
    }

    public function failWhenSqlContains(string $needle): void
    {
        $this->failureNeedles[] = $needle;
    }

    public function failOnMatchingQueryAttempt(string $needle, int $attempt): void
    {
        $this->failureAttempts[$needle] = $attempt;
    }

    public function runSelect(string $sql, array $params = []): array
    {
        return $this->execute($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function runInsert(string $sql, array $params = []): int
    {
        $this->execute($sql, $params);

        return (int) $this->pdo->lastInsertId();
    }

    public function runStatement(string $sql, array $params = []): int
    {
        return $this->execute($sql, $params)->rowCount();
    }

    public function beginTransaction(): void
    {
        $this->pdo->beginTransaction();
    }

    public function commit(): void
    {
        $this->pdo->commit();
    }

    public function rollback(): void
    {
        $this->pdo->rollBack();
    }

    /** @param array<int|string,mixed> $params */
    private function execute(string $sql, array $params): PDOStatement
    {
        $this->queryLog[] = ['sql' => $sql, 'params' => $params];
        foreach ($this->failureNeedles as $needle) {
            if (str_contains($sql, $needle)) {
                throw new RuntimeException("Forced failure for {$needle}");
            }
        }
        foreach ($this->failureAttempts as $needle => $attempt) {
            if (!str_contains($sql, $needle)) {
                continue;
            }

            $this->queryAttempts[$needle] = ($this->queryAttempts[$needle] ?? 0) + 1;
            if ($this->queryAttempts[$needle] === $attempt) {
                throw new RuntimeException("Forced failure for {$needle} on attempt {$attempt}");
            }
        }

        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return $statement;
    }
}
