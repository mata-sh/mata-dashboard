<?php

declare(strict_types=1);

namespace Mata\Tests\Integration;

use Mata\Models\ConfigSyncRepository;
use Mata\Tests\Helpers\SqliteTestDatabase;
use Mata\Tests\TestCase;
use PDO;

/**
 * @covers \Mata\Models\ConfigSyncRepository
 *
 * @internal
 */
final class ConfigSyncRepositorySqlIntegrationTest extends TestCase
{
    private SqliteTestDatabase $db;
    private ConfigSyncRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = new SqliteTestDatabase();
        $this->createSchema($this->db);
        $this->repository = new ConfigSyncRepository($this->db);
    }

    private function createSchema(SqliteTestDatabase $db): void
    {
        $db->pdo()->exec(<<<'SQL'
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

    public function testUpsertApplicationInsertsNewRow(): void
    {
        $this->assertTrue($this->repository->upsertApplication('shop', 'Shop', 'prod'));

        $this->assertSame(['shop' => 1], $this->repository->fetchApplicationIdMap());
    }

    public function testUpsertApplicationUpdatesExistingRow(): void
    {
        $this->repository->upsertApplication('shop', 'Shop', 'prod');

        $this->assertFalse($this->repository->upsertApplication('shop', 'Shop v2', 'stg'));
        $this->assertSame(['shop' => 1], $this->repository->fetchApplicationIdMap());

        $row = $this->db->pdo()
            ->query('SELECT display_name, app_env FROM applications WHERE id = 1')
            ->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('Shop v2', $row['display_name']);
        $this->assertSame('stg', $row['app_env']);
    }

    public function testUpsertApplicationSkipsUpdateWhenUnchanged(): void
    {
        $this->repository->upsertApplication('shop', 'Shop', 'prod');
        $this->db->pdo()->exec("UPDATE applications SET updated_at = 'sentinel' WHERE id = 1");

        $this->assertFalse($this->repository->upsertApplication('shop', 'Shop', 'prod'));

        $updatedAt = $this->db->pdo()
            ->query('SELECT updated_at FROM applications WHERE id = 1')
            ->fetchColumn();
        $this->assertSame('sentinel', $updatedAt);
    }
}
