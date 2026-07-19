<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use Mata\Models\ConfigSyncRepository;
use Mata\Services\Foundation\IdResolver;
use Mata\Services\Sync\AppInventorySync;
use Mata\Tests\TestCase;
use Mata\Tests\Unit\Models\SqliteConfigSyncDatabase;
use Monolog\Logger;

/**
 * @covers \Mata\Services\Sync\AppInventorySync
 *
 * @internal
 */
final class AppInventorySyncTest extends TestCase
{
    private ConfigSyncRepository $repository;
    private IdResolver $idResolver;
    private AppInventorySync $sync;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new ConfigSyncRepository(new SqliteConfigSyncDatabase());
        $this->idResolver = new IdResolver($this->repository);
        $this->idResolver->refreshFromRepository();
        $this->sync = new AppInventorySync($this->repository, $this->idResolver, new Logger('test'));
    }

    public function testSyncUpsertsApplicationsAndRefreshesResolver(): void
    {
        $synced = $this->sync->sync([
            'shop' => ['title' => 'Shop', 'env' => 'live'],
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
            '' => ['title' => 'Skip', 'env' => 'live'],
            'broken' => 'not-an-array',
            'shop' => ['title' => 'Shop', 'env' => 'live'],
        ]);

        $this->assertSame(1, $synced);
        $this->assertSame(['shop' => 1], $this->repository->fetchApplicationIdMap());
    }

    public function testSyncFallsBackToCodeWhenTitleMissing(): void
    {
        $this->sync->sync(['shop' => ['env' => 'live']]);

        $this->assertSame(1, $this->idResolver->resolveApplication('shop')['id']);
    }

    public function testSyncReturnsZeroForEmptyPayload(): void
    {
        $this->assertSame(0, $this->sync->sync([]));
        $this->assertSame([], $this->repository->fetchApplicationIdMap());
    }
}
