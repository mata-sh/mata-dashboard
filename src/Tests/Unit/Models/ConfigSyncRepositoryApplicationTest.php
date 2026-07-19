<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Models;

use Mata\Models\ConfigSyncRepository;
use Mata\Tests\TestCase;
use PDO;

/**
 * @covers \Mata\Models\ConfigSyncRepository
 *
 * @internal
 */
final class ConfigSyncRepositoryApplicationTest extends TestCase
{
    private SqliteConfigSyncDatabase $db;
    private ConfigSyncRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = new SqliteConfigSyncDatabase();
        $this->repository = new ConfigSyncRepository($this->db);
    }

    public function testUpsertApplicationInsertsNewRow(): void
    {
        $this->assertTrue($this->repository->upsertApplication('shop', 'Shop', 'live'));

        $this->assertSame(['shop' => 1], $this->repository->fetchApplicationIdMap());
    }

    public function testUpsertApplicationUpdatesExistingRow(): void
    {
        $this->repository->upsertApplication('shop', 'Shop', 'live');

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
        $this->repository->upsertApplication('shop', 'Shop', 'live');
        $this->db->pdo()->exec("UPDATE applications SET updated_at = 'sentinel' WHERE id = 1");

        $this->assertFalse($this->repository->upsertApplication('shop', 'Shop', 'live'));

        $updatedAt = $this->db->pdo()
            ->query('SELECT updated_at FROM applications WHERE id = 1')
            ->fetchColumn();
        $this->assertSame('sentinel', $updatedAt);
    }
}
