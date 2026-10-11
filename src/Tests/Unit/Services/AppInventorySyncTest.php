<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use Mata\Models\Sync\ConfigSyncRepository;
use Mata\Services\Identity\ServerApplicationIdResolver;
use Mata\Services\Sync\AppInventorySync;
use Mata\Tests\Helpers\SqliteTestDatabase;
use Mata\Tests\TestCase;
use Monolog\Logger;

/**
 * @covers \Mata\Services\Sync\AppInventorySync
 *
 * @internal
 */
final class AppInventorySyncTest extends TestCase
{
    private ConfigSyncRepository $repository;
    private ServerApplicationIdResolver $idResolver;
    private AppInventorySync $sync;

    protected function setUp(): void
    {
        parent::setUp();

        $db = new SqliteTestDatabase();
        $this->createSchema($db);
        $this->repository = new ConfigSyncRepository($db);
        $this->idResolver = new ServerApplicationIdResolver($this->repository);
        $this->idResolver->refreshFromRepository();
        $this->sync = new AppInventorySync($this->repository, $this->idResolver, new Logger('test'));
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

    public function testSyncUpsertsApplicationsAndRefreshesResolver(): void
    {
        $synced = $this->sync->sync([
            'shop' => ['title' => 'Shop', 'env' => 'prod'],
            'api' => ['title' => '', 'env' => 'stg'],
        ]);

        $this->assertSame(2, $synced);

        $shop = $this->idResolver->resolveApplication('shop');
        $this->assertSame(1, $shop['id']);

        $api = $this->idResolver->resolveApplication('api');
        $this->assertSame(2, $api['id']);
        $this->assertSame(['shop' => 1, 'api' => 2], $this->repository->fetchApplicationIdMap());
    }

    public function testSyncSkipsInvalidEntries(): void
    {
        $synced = $this->sync->sync([
            '' => ['title' => 'Skip', 'env' => 'prod'],
            'broken' => 'not-an-array',
            'shop' => ['title' => 'Shop', 'env' => 'prod'],
        ]);

        $this->assertSame(1, $synced);
        $this->assertSame(['shop' => 1], $this->repository->fetchApplicationIdMap());
    }

    public function testSyncFallsBackToCodeWhenTitleMissing(): void
    {
        $this->sync->sync(['shop' => ['env' => 'prod']]);

        $this->assertSame(1, $this->idResolver->resolveApplication('shop')['id']);
    }

    public function testSyncReturnsZeroForEmptyPayload(): void
    {
        $this->assertSame(0, $this->sync->sync([]));
        $this->assertSame([], $this->repository->fetchApplicationIdMap());
    }
}
