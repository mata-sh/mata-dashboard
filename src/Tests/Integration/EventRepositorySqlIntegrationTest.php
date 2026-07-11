<?php

declare(strict_types=1);

namespace Mata\Tests\Integration;

use Mata\Core\DatabaseInterface;
use Mata\Models\EventRepository;
use Mata\Tests\TestCase;
use PDO;

/**
 * @internal
 *
 * @covers \Mata\Models\EventRepository
 */
final class EventRepositorySqlIntegrationTest extends TestCase
{
    private SqliteEventRepositoryDatabase $db;
    private EventRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = new SqliteEventRepositoryDatabase();
        $this->createSchema();
        $this->seedData();
        $this->repository = new EventRepository($this->db);
    }

    public function testGetLatestEventsExecutesSelectWithAppEnv(): void
    {
        $rows = $this->repository->getLatestEvents(10);

        $this->assertCount(2, $rows);
        $this->assertArrayHasKey('app_env', $rows[0]);
        $this->assertSame('prod', $rows[0]['app_env']);
    }

    public function testGetAlarmDecisionCandidatesExecutesSelectWithAppEnv(): void
    {
        $rows = $this->repository->getAlarmDecisionCandidates(10, 'critical');

        $this->assertCount(1, $rows);
        $this->assertSame(2, (int) $rows[0]['id']);
        $this->assertSame('server-a', $rows[0]['server_name']);
        $this->assertSame('app-a', $rows[0]['app_code']);
        $this->assertSame('App A', $rows[0]['app_name']);
        $this->assertSame('prod', $rows[0]['app_env']);
    }

    private function createSchema(): void
    {
        $this->db->pdo()->exec(<<<'SQL'
            CREATE TABLE servers (
                id INTEGER PRIMARY KEY,
                name TEXT NOT NULL
            );

            CREATE TABLE applications (
                id INTEGER PRIMARY KEY,
                code TEXT NOT NULL,
                display_name TEXT NOT NULL,
                app_env TEXT
            );

            CREATE TABLE events (
                id INTEGER PRIMARY KEY,
                server_id INTEGER,
                application_id INTEGER,
                type TEXT NOT NULL,
                message TEXT NOT NULL,
                context TEXT,
                level TEXT NOT NULL,
                datetime TEXT NOT NULL,
                evaluated_at TEXT
            );

            CREATE TABLE alarms (
                id INTEGER PRIMARY KEY,
                event_id INTEGER UNIQUE NOT NULL,
                created_at TEXT NOT NULL,
                acknowledged_at TEXT,
                acknowledged_by TEXT
            );
            SQL);
    }

    private function seedData(): void
    {
        $this->db->pdo()->exec(<<<'SQL'
            INSERT INTO servers (id, name)
            VALUES (1, 'server-a');

            INSERT INTO applications (id, code, display_name, app_env)
            VALUES (10, 'app-a', 'App A', 'prod');

            INSERT INTO events (id, server_id, application_id, type, message, context, level, datetime, evaluated_at)
            VALUES
                (1, 1, 10, 'cpu', 'CPU high', '{"metric":"cpu"}', 'warning', '2026-01-01 00:00:00', NULL),
                (2, 1, 10, 'disk', 'Disk full', '{"metric":"disk"}', 'critical', '2026-01-01 00:01:00', NULL);
            SQL);
    }
}

final class SqliteEventRepositoryDatabase implements DatabaseInterface
{
    private PDO $pdo;

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
