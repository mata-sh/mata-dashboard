<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use Mata\Models\AppFatalStateRepository;
use Mata\Services\Event\Dto\EventCommand;
use Mata\Services\Event\EventManager;
use Mata\Services\Identity\ServerApplicationIdResolver;
use Mata\Services\Monitor\AppErrorMonitor;
use Mata\Services\Monitor\Dto\AppFatalErrorPolicy;
use Mata\Services\Monitor\Dto\AppLogSizePolicy;
use Mata\Services\Monitor\LogSizeEvaluator;
use Mata\Tests\Helpers\SqliteTestDatabase;
use Mata\Tests\TestCase;
use Mata\Utils\Tools;
use Mata\Views\Support\NumberFormatter;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\NullLogger;

/**
 * @covers \Mata\Services\Monitor\AppErrorMonitor
 *
 * @internal
 */
final class AppErrorMonitorTest extends TestCase
{
    private SqliteTestDatabase $db;
    private AppFatalStateRepository $states;
    private EventManager&MockObject $events;

    protected function setUp(): void
    {
        $this->db = new SqliteTestDatabase();
        $this->db->pdo()->exec('CREATE TABLE app_fatal_monitor_state (server_id INTEGER, application_id INTEGER, fatal_cursor TEXT, fatal_count INTEGER, created_at TEXT, updated_at TEXT, UNIQUE(server_id, application_id))');
        $this->states = new AppFatalStateRepository($this->db);
        $this->events = $this->createMock(EventManager::class);
    }

    public function testRecordsInitialFatalAndPersistsCursorOnlyAfterEventRecorded(): void
    {
        $this->events->expects($this->once())->method('handle')
            ->with($this->callback(static fn (EventCommand $event): bool => $event->type === 'fatal_errors_occurring'
                && $event->message === 'Fatal Error Detected (Count: 3): shop on Server: srv'
                && $event->metric?->additional === [
                    'previous_fatal_count' => null, 'fatal_count_delta' => 3,
                    'previous_fatal_cursor' => null,
                    'fatal_cursor' => self::cursor('a'), 'detection_reason' => 'initial_observation',
                ]))
            ->willReturn(['eventRecorded' => true, 'eventId' => 1]);

        $result = $this->monitor()->monitor('srv', ['shop' => $this->app(3, self::cursor('a'))]);

        $this->assertSame(1, $result->fatalEventsRecorded);
        $this->assertSame(self::cursor('a'), $this->states->get(1, 2)?->fatalCursor);
    }

    public function testUnchangedCursorSkipsRegardlessOfCountAndChangedCursorRecords(): void
    {
        $this->states->upsert(1, 2, self::cursor('a'), 3);
        $this->events->expects($this->once())->method('handle')
            ->with($this->callback(static fn (EventCommand $event): bool => $event->metric?->additional['detection_reason'] === 'cursor_changed'
                && $event->metric->additional['previous_fatal_count'] === 3
                && $event->metric->value === 3))
            ->willReturn(['eventRecorded' => true, 'eventId' => 1]);

        $unchanged = $this->monitor()->monitor('srv', ['shop' => $this->app(9, self::cursor('a'))]);
        $changed = $this->monitor()->monitor('srv', ['shop' => $this->app(3, self::cursor('b'))]);

        $this->assertSame(1, $unchanged->unchangedFatalCursors);
        $this->assertSame(1, $changed->fatalEventsRecorded);
        $this->assertSame(self::cursor('b'), $this->states->get(1, 2)?->fatalCursor);
    }

    public function testNotRecordedFatalRemainsPendingAndZeroClearsState(): void
    {
        $this->events->expects($this->once())->method('handle')->willReturn(['eventRecorded' => false, 'eventId' => null]);
        $notRecorded = $this->monitor()->monitor('srv', ['shop' => $this->app(3, self::cursor('a'))]);
        $this->assertSame(1, $notRecorded->eventsNotRecorded);
        $this->assertNull($this->states->get(1, 2));

        $this->states->upsert(1, 2, self::cursor('a'), 3);
        $this->events = $this->createMock(EventManager::class);
        $this->events->expects($this->never())->method('handle');
        $cleared = $this->monitor()->monitor('srv', ['shop' => $this->app(0, null)]);
        $this->assertSame(0, $cleared->fatalEventsRecorded);
        $this->assertNull($this->states->get(1, 2));
    }

    public function testRecordsOversizedLogWithExistingEventContract(): void
    {
        $this->events->expects($this->once())->method('getServerDataThresholdLevel')
            ->with(60000, 'log_filesize_kb')->willReturn('alarm');
        $this->events->expects($this->once())->method('handle')
            ->with($this->callback(static fn (EventCommand $event): bool => $event->type === 'logfile_too_large'
                && $event->message === 'Log too large (60000 KB) - logs/error.log : shop on Server: srv'
                && $event->level === 'critical' && $event->server === 'srv' && $event->app === 'shop'))
            ->willReturn(['eventRecorded' => true, 'eventId' => 1]);
        $app = $this->app(0, null);
        $app['error_monitoring_enabled'] = false;
        $app['logsize_monitoring_enabled'] = true;
        $app['logs'] = ['logs/error.log' => 60000];

        $result = $this->monitor()->monitor('srv', ['shop' => $app]);

        $this->assertSame(1, $result->oversizedLogsDetected);
        $this->assertSame(1, $result->logfileEventsRecorded);
    }

    public function testInvalidCursorVersionIsRejectedWithoutCountFallback(): void
    {
        $this->events->expects($this->never())->method('handle');

        $result = $this->monitor()->monitor('srv', ['shop' => $this->app(3, 'v2:' . str_repeat('a', 64))]);

        $this->assertSame(1, $result->failures);
        $this->assertSame(0, $result->fatalEventsRecorded);
    }

    public function testFatalFailureDoesNotStopLaterApplication(): void
    {
        $this->events->expects($this->once())->method('handle')->willReturn(['eventRecorded' => true, 'eventId' => 1]);
        $broken = $this->app(3, self::cursor('a'));
        $broken['status']['fatal_cursor'] = null;

        $result = $this->monitor()->monitor('srv', [
            'broken' => $broken,
            'shop' => $this->app(3, self::cursor('b')),
        ]);

        $this->assertSame(1, $result->failures);
        $this->assertSame(1, $result->fatalEventsRecorded);
    }

    public function testServerSettingsIndependentlyDisableFatalAndLogSizeMonitoring(): void
    {
        $this->events->expects($this->once())->method('getServerDataThresholdLevel')->willReturn('alarm');
        $this->events->expects($this->once())->method('handle')->willReturn(['eventRecorded' => true, 'eventId' => 1]);
        $fatalDisabled = $this->app(3, self::cursor('a'));
        $fatalDisabled['logsize_monitoring_enabled'] = true;
        $fatalDisabled['logs'] = ['error.log' => 60000];
        $first = $this->monitor(['error_monitoring_enabled' => false])->monitor('srv', ['shop' => $fatalDisabled]);
        $this->assertSame(0, $first->fatalEventsRecorded);
        $this->assertSame(1, $first->logfileEventsRecorded);

        $this->events = $this->createMock(EventManager::class);
        $this->events->expects($this->once())->method('handle')->willReturn(['eventRecorded' => true, 'eventId' => 2]);
        $second = $this->monitor(['logsize_monitoring_enabled' => false])->monitor('srv', ['shop' => $this->app(3, self::cursor('b'))]);
        $this->assertSame(1, $second->fatalEventsRecorded);
        $this->assertSame(0, $second->logfileEventsRecorded);
    }

    public function testRecordedFatalIsCountedWhenCursorStateWriteFails(): void
    {
        $this->db->failWhenSqlContains('INSERT INTO app_fatal_monitor_state');
        $this->events->expects($this->once())->method('handle')->willReturn(['eventRecorded' => true, 'eventId' => 1]);

        $result = $this->monitor()->monitor('srv', ['shop' => $this->app(3, self::cursor('a'))]);

        $this->assertSame(1, $result->fatalEventsRecorded);
        $this->assertSame(1, $result->failures);
        $this->assertNull($this->states->get(1, 2));
    }

    public function testIndependentDisabledSettingsPreserveFatalState(): void
    {
        $this->states->upsert(1, 2, self::cursor('a'), 3);
        $this->events->expects($this->never())->method('handle');
        $app = $this->app(0, null);
        $app['error_monitoring_enabled'] = false;
        $app['logsize_monitoring_enabled'] = false;

        $result = $this->monitor()->monitor('srv', ['shop' => $app]);

        $this->assertSame(1, $result->errorMonitoringDisabled);
        $this->assertSame(1, $result->logsizeMonitoringDisabled);
        $this->assertSame(self::cursor('a'), $this->states->get(1, 2)?->fatalCursor);
    }

    /** @param array<string,mixed> $serverConfig */
    private function monitor(array $serverConfig = []): AppErrorMonitor
    {
        $resolver = new ServerApplicationIdResolver(serverIdMap: ['srv' => 1], applicationIdMap: ['shop' => 2]);
        $tools = new class ('/tmp') extends Tools {
            public function formatKilobytes(int $kilobytes): string
            {
                return $kilobytes . ' KB';
            }
        };

        return new AppErrorMonitor(
            $this->events,
            $resolver,
            $this->states,
            AppFatalErrorPolicy::fromServerConfig($serverConfig),
            AppLogSizePolicy::fromServerConfig($serverConfig),
            new LogSizeEvaluator($tools, $this->events),
            new NullLogger(),
            new NumberFormatter(),
        );
    }

    /** @return array<string,mixed> */
    private function app(int $fatal, ?string $cursor): array
    {
        return [
            'error_monitoring_enabled' => true,
            'logsize_monitoring_enabled' => false,
            'status' => ['fatal' => $fatal, 'fatal_cursor' => $cursor],
            'logs' => [],
        ];
    }

    private static function cursor(string $character): string
    {
        return 'v1:' . str_repeat($character, 64);
    }
}
