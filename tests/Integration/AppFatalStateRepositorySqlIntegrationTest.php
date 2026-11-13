<?php

declare(strict_types=1);

namespace Mata\Tests\Integration;

use Mata\Models\Monitor\AppFatalStateRepository;
use Mata\Tests\Helpers\SqliteTestDatabase;
use Mata\Tests\TestCase;

/**
 * @covers \Mata\Models\Monitor\AppFatalStateRepository
 *
 * @internal
 */
final class AppFatalStateRepositorySqlIntegrationTest extends TestCase
{
    private AppFatalStateRepository $repository;

    protected function setUp(): void
    {
        $db = new SqliteTestDatabase();
        $db->pdo()->exec('CREATE TABLE app_fatal_monitor_state (server_id INTEGER, application_id INTEGER, fatal_cursor TEXT, fatal_count INTEGER, created_at TEXT, updated_at TEXT, UNIQUE(server_id, application_id))');
        $this->repository = new AppFatalStateRepository($db);
    }

    public function testReadsReplacesClearsAndIsolatesStateByContext(): void
    {
        $this->assertNull($this->repository->get(1, 2));
        $this->repository->upsert(1, 2, 'v1:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 3);
        $this->repository->upsert(2, 2, 'v1:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', 4);
        $this->repository->upsert(1, 2, 'v1:cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc', 5);

        $state = $this->repository->get(1, 2);
        $this->assertSame('v1:cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc', $state?->fatalCursor);
        $this->assertSame(5, $state?->fatalCount);
        $this->assertSame(4, $this->repository->get(2, 2)?->fatalCount);

        $this->repository->clear(1, 2);
        $this->assertNull($this->repository->get(1, 2));
        $this->assertSame(4, $this->repository->get(2, 2)?->fatalCount);
    }
}
