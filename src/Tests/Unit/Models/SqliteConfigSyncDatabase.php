<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Models;

use Mata\Core\DatabaseInterface;
use PDO;

/** In-memory SQLite database for ConfigSyncRepository tests. */
final class SqliteConfigSyncDatabase implements DatabaseInterface
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->pdo->exec(<<<'SQL'
            CREATE TABLE servers (
                id INTEGER PRIMARY KEY,
                name TEXT NOT NULL
            );

            CREATE TABLE applications (
                id INTEGER PRIMARY KEY,
                code TEXT NOT NULL,
                display_name TEXT NOT NULL,
                app_env TEXT,
                created_at TEXT,
                updated_at TEXT
            );
            SQL);
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function runQuery(string $sql, array $params = []): array|int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $firstWord = strtok(trim($sql), ' ');
        $sqlType = strtoupper($firstWord === false ? '' : $firstWord);

        return match ($sqlType) {
            'INSERT' => (int) $this->pdo->lastInsertId(),
            'UPDATE', 'DELETE' => $stmt->rowCount(),
            default => $stmt->fetchAll(PDO::FETCH_ASSOC),
        };
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
}
