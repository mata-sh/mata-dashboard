<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use Mata\Database\DatabaseInterface;
use Mata\Database\NullDatabase;
use Mata\Models\Sync\ConfigSyncRepository;
use Mata\Services\Event\Dto\EventRecordResult;
use Mata\Services\Event\EventRecorderService;
use Mata\Services\Sync\ServerConfigDatabaseSync;
use Mata\Tests\TestCase;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PDOException;
use Psr\Log\NullLogger;

/**
 * @covers \Mata\Services\Sync\ServerConfigDatabaseSync
 *
 * @internal
 */
final class ServerConfigDatabaseSyncTest extends TestCase
{
    private const SERVERS = ['web-1' => ['api_url' => 'https://web-1.example.test/api', 'api_key' => 'key']];

    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        $this->cleanupTempFiles($this->tempFiles);
        $this->tempFiles = [];
    }

    public function testSkipsWithoutDatabase(): void
    {
        $repository = $this->createMock(ConfigSyncRepository::class);
        $repository->expects($this->never())->method('fetchSyncTracking');

        $this->sync(new NullDatabase(new NullLogger()), $repository, $this->createStub(EventRecorderService::class))
            ->syncIfNeeded($this->serversFile(), self::SERVERS);
    }

    public function testSkipsWhenNoServersWereLoaded(): void
    {
        $repository = $this->createMock(ConfigSyncRepository::class);
        $repository->expects($this->never())->method('fetchSyncTracking');

        $this->sync($this->createStub(DatabaseInterface::class), $repository, $this->createStub(EventRecorderService::class))
            ->syncIfNeeded($this->serversFile(), []);
    }

    public function testFailedSyncIsReportedWithoutThrowingWhenTrackingFailsToo(): void
    {
        $repository = $this->createMock(ConfigSyncRepository::class);
        $repository->method('fetchSyncTracking')->willReturn(null);
        $repository->method('upsertServer')->willThrowException(new PDOException('server has gone away'));
        $repository->expects($this->once())
            ->method('upsertSyncTracking')
            ->willThrowException(new PDOException('server has gone away'))
        ;

        $recorder = $this->createMock(EventRecorderService::class);
        $recorder->method('hasActiveCooldown')->willReturn(false);
        $recorder->expects($this->once())
            ->method('recordEvent')
            ->with('settings_sync_error', $this->stringContains('server has gone away'))
            ->willReturn(new EventRecordResult(1, null, null, null, null, null))
        ;

        $this->sync($this->createStub(DatabaseInterface::class), $repository, $recorder)
            ->syncIfNeeded($this->serversFile(), self::SERVERS);
    }

    private function sync(DatabaseInterface $db, ConfigSyncRepository $repository, EventRecorderService $recorder): ServerConfigDatabaseSync
    {
        return new ServerConfigDatabaseSync($db, new Logger('test', [new TestHandler()]), $this->eventManagerFor($recorder), $repository);
    }

    private function serversFile(): string
    {
        $file = $this->createTempFile('{}', '.json');
        $this->tempFiles[] = $file;

        return $file;
    }
}
