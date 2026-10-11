<?php

declare(strict_types=1);

namespace Mata\Tests\Integration;

use Mata\Models\Monitor\AppLibraryAuditStateRepository;
use Mata\Tests\Helpers\SqliteTestDatabase;
use Mata\Tests\TestCase;

/**
 * @covers \Mata\Models\Monitor\AppLibraryAuditStateRepository
 *
 * @internal
 */
final class AppLibraryAuditStateRepositorySqlIntegrationTest extends TestCase
{
    private SqliteTestDatabase $db;
    private AppLibraryAuditStateRepository $repository;

    protected function setUp(): void
    {
        $this->db = new SqliteTestDatabase();
        $this->db->pdo()->exec('CREATE TABLE app_library_audit_state (server_id INTEGER, application_id INTEGER, advisory_ids TEXT, created_at TEXT, updated_at TEXT, UNIQUE(server_id, application_id))');
        $this->repository = new AppLibraryAuditStateRepository($this->db);
    }

    public function testReadsReplacesClearsAndIsolatesStateByContext(): void
    {
        $this->assertNull($this->repository->get(1, 2));
        $this->repository->upsert(1, 2, ['PKSA-a']);
        $this->repository->upsert(2, 2, ['PKSA-b', 'PKSA-c']);
        $this->repository->upsert(1, 2, ['PKSA-a', 'PKSA-d']);

        $this->assertSame(['PKSA-a', 'PKSA-d'], $this->repository->get(1, 2)?->advisoryIds);
        $this->assertSame(['PKSA-b', 'PKSA-c'], $this->repository->get(2, 2)?->advisoryIds);

        $this->repository->clear(1, 2);
        $this->assertNull($this->repository->get(1, 2));
        $this->assertSame(['PKSA-b', 'PKSA-c'], $this->repository->get(2, 2)?->advisoryIds);
    }

    public function testCorruptStoredIdsReadAsEmptySet(): void
    {
        $this->db->pdo()->exec("INSERT INTO app_library_audit_state (server_id, application_id, advisory_ids) VALUES (1, 2, 'not json')");

        $this->assertSame([], $this->repository->get(1, 2)?->advisoryIds);
    }
}
