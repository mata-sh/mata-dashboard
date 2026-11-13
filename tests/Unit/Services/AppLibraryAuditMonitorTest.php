<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use Mata\Models\Monitor\AppLibraryAuditStateRepository;
use Mata\Services\Collect\ServerDataCollectorInterface;
use Mata\Services\Event\Dto\EventCommand;
use Mata\Services\Event\EventManager;
use Mata\Services\Identity\ServerApplicationIdResolver;
use Mata\Services\Monitor\AppLibraryAuditMonitor;
use Mata\Tests\Helpers\SqliteTestDatabase;
use Mata\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use Psr\Log\NullLogger;

/**
 * @covers \Mata\Services\Monitor\AppLibraryAuditMonitor
 *
 * @internal
 */
final class AppLibraryAuditMonitorTest extends TestCase
{
    private SqliteTestDatabase $db;
    private AppLibraryAuditStateRepository $states;
    private EventManager&MockObject $events;

    protected function setUp(): void
    {
        $this->db = new SqliteTestDatabase();
        $this->db->pdo()->exec('CREATE TABLE app_library_audit_state (server_id INTEGER, application_id INTEGER, advisory_ids TEXT, created_at TEXT, updated_at TEXT, UNIQUE(server_id, application_id))');
        $this->states = new AppLibraryAuditStateRepository($this->db);
        $this->events = $this->createMock(EventManager::class);
    }

    public function testInitialAdvisoriesRecordCriticalEventAndPersistSortedIds(): void
    {
        $this->events->expects($this->once())->method('handle')
            ->with($this->callback(static fn (EventCommand $event): bool => $event->type === 'library_outdated_security'
                && $event->message === 'Library Security Advisories (New: 2, Total: 2): shop on Server: srv'
                && $event->level === 'critical' && $event->server === 'srv' && $event->app === 'shop'
                && $event->context === []
                && $event->metric?->value === 2
                && $event->metric->additional === [
                    'new_advisory_ids' => ['PKSA-a', 'PKSA-b'],
                    'new_packages' => ['vendor/a', 'vendor/b'],
                    'worst_new_severity' => 'high',
                    'previous_advisory_count' => null,
                    'detection_reason' => 'initial_observation',
                ]))
            ->willReturn(['eventRecorded' => true, 'eventId' => 1]);

        $result = $this->monitor()->monitor('srv', $this->collector([
            self::advisory('PKSA-b', 'vendor/b', 'low'),
            self::advisory('PKSA-a', 'vendor/a', 'high'),
        ]));

        $this->assertSame(1, $result->eventsRecorded);
        $this->assertSame(['PKSA-a', 'PKSA-b'], $this->states->get(1, 2)?->advisoryIds);
    }

    public function testUnchangedAdvisoriesStayQuietAndOnlyNewOnesAreReported(): void
    {
        $this->states->upsert(1, 2, ['PKSA-a']);
        $this->events->expects($this->once())->method('handle')
            ->with($this->callback(static fn (EventCommand $event): bool => $event->level === 'warning'
                && $event->message === 'Library Security Advisories (New: 1, Total: 2): shop on Server: srv'
                && $event->metric?->additional['new_advisory_ids'] === ['PKSA-b']
                && $event->metric->additional['previous_advisory_count'] === 1
                && $event->metric->additional['detection_reason'] === 'new_advisories'))
            ->willReturn(['eventRecorded' => true, 'eventId' => 1]);

        $unchanged = $this->monitor()->monitor('srv', $this->collector([self::advisory('PKSA-a', 'vendor/a', 'critical')]));
        $added = $this->monitor()->monitor('srv', $this->collector([
            self::advisory('PKSA-a', 'vendor/a', 'critical'),
            self::advisory('PKSA-b', 'vendor/b', 'medium'),
        ]));

        $this->assertSame(1, $unchanged->unchangedAdvisories);
        $this->assertSame(1, $added->eventsRecorded);
        $this->assertSame(['PKSA-a', 'PKSA-b'], $this->states->get(1, 2)?->advisoryIds);
    }

    public function testFixedAdvisoryShrinksStateSoItAlarmsAgainWhenReintroduced(): void
    {
        $this->states->upsert(1, 2, ['PKSA-a', 'PKSA-b']);
        $this->events->expects($this->once())->method('handle')
            ->with($this->callback(static fn (EventCommand $event): bool => $event->metric?->additional['new_advisory_ids'] === ['PKSA-b']))
            ->willReturn(['eventRecorded' => true, 'eventId' => 1]);

        $fixed = $this->monitor()->monitor('srv', $this->collector([self::advisory('PKSA-a', 'vendor/a', 'low')]));
        $this->assertSame(0, $fixed->eventsRecorded);
        $this->assertSame(['PKSA-a'], $this->states->get(1, 2)?->advisoryIds);

        $this->monitor()->monitor('srv', $this->collector([
            self::advisory('PKSA-a', 'vendor/a', 'low'),
            self::advisory('PKSA-b', 'vendor/b', 'low'),
        ]));
    }

    public function testCleanAuditClearsState(): void
    {
        $this->states->upsert(1, 2, ['PKSA-a']);
        $this->events->expects($this->never())->method('handle');

        $result = $this->monitor()->monitor('srv', $this->collector([]));

        $this->assertSame(1, $result->appsChecked);
        $this->assertNull($this->states->get(1, 2));
    }

    /** @param null|array<string,mixed> $response */
    #[DataProvider('unavailableAuditProvider')]
    public function testUnavailableAuditLeavesStateUntouched(?array $response): void
    {
        $this->states->upsert(1, 2, ['PKSA-a']);
        $this->events->expects($this->never())->method('handle');
        $collector = $this->createStub(ServerDataCollectorInterface::class);
        $collector->method('getApps')->willReturn(['result' => ['shop' => self::app()], 'error' => null]);
        $collector->method('fetchAppComposerAudit')->willReturn($response);

        $result = $this->monitor()->monitor('srv', $collector);

        $this->assertSame(1, $result->auditsUnavailable);
        $this->assertSame(['PKSA-a'], $this->states->get(1, 2)?->advisoryIds);
    }

    /** @return array<string,array{0:null|array<string,mixed>}> */
    public static function unavailableAuditProvider(): array
    {
        return [
            'no response' => [null],
            'node error' => [['result' => null, 'error' => ['message' => 'down']]],
            'audit error' => [['result' => ['status' => 'error', 'advisories' => []], 'error' => null]],
        ];
    }

    public function testUnrecordedEventKeepsPreviousStateForRetry(): void
    {
        $this->events->expects($this->once())->method('handle')->willReturn(['eventRecorded' => false, 'eventId' => null]);

        $result = $this->monitor()->monitor('srv', $this->collector([self::advisory('PKSA-a', 'vendor/a', 'high')]));

        $this->assertSame(1, $result->eventsNotRecorded);
        $this->assertNull($this->states->get(1, 2));
    }

    /** @param array<string,mixed> $app */
    #[DataProvider('skippedAppProvider')]
    public function testSkippedAppsAreNeverAudited(array $app): void
    {
        $this->events->expects($this->never())->method('handle');
        $collector = $this->createMock(ServerDataCollectorInterface::class);
        $collector->method('getApps')->willReturn(['result' => ['shop' => $app], 'error' => null]);
        $collector->expects($this->never())->method('fetchAppComposerAudit');

        $result = $this->monitor()->monitor('srv', $collector);

        $this->assertSame(1, $result->appsSkipped);
        $this->assertSame(0, $result->appsChecked);
    }

    /** @return array<string,array{0:array<string,mixed>}> */
    public static function skippedAppProvider(): array
    {
        return [
            'composer disabled' => [['composer' => false]],
            'library audit disabled' => [['composer' => true, 'monitoring' => ['libraryaudit' => false]]],
            'events disabled' => [['composer' => true, 'monitoring' => ['events' => false]]],
        ];
    }

    public function testDisabledAlarmsStampAlarmsSuppressed(): void
    {
        $this->events->expects($this->once())->method('handle')
            ->with($this->callback(static fn (EventCommand $event): bool => $event->context === ['alarms_suppressed' => true]))
            ->willReturn(['eventRecorded' => true, 'eventId' => 1]);

        $this->monitor()->monitor('srv', $this->collector(
            [self::advisory('PKSA-a', 'vendor/a', 'low')],
            self::app(['alarms' => false]),
        ));
    }

    public function testNumericAdvisoryIdsArePersistedAsStrings(): void
    {
        $this->events->expects($this->once())->method('handle')->willReturn(['eventRecorded' => true, 'eventId' => 1]);

        $this->monitor()->monitor('srv', $this->collector([self::advisory('12345', 'vendor/a', 'low')]));

        $this->assertSame(['12345'], $this->states->get(1, 2)?->advisoryIds);
    }

    public function testUnavailableAppsCountAsOneFailure(): void
    {
        $this->events->expects($this->never())->method('handle');
        $collector = $this->createStub(ServerDataCollectorInterface::class);
        $collector->method('getApps')->willReturn(null);

        $result = $this->monitor()->monitor('srv', $collector);

        $this->assertSame(1, $result->failures);
        $this->assertSame(0, $result->appsSeen);
    }

    public function testFailingAppDoesNotStopLaterApplication(): void
    {
        $this->events->expects($this->once())->method('handle')->willReturn(['eventRecorded' => true, 'eventId' => 1]);
        $collector = $this->createStub(ServerDataCollectorInterface::class);
        $collector->method('getApps')->willReturn(['result' => [
            'unknown' => self::app(),
            'shop' => self::app(),
        ], 'error' => null]);
        $collector->method('fetchAppComposerAudit')->willReturn(self::audit([self::advisory('PKSA-a', 'vendor/a', 'low')]));

        $result = $this->monitor()->monitor('srv', $collector);

        $this->assertSame(1, $result->failures);
        $this->assertSame(1, $result->eventsRecorded);
    }

    private function monitor(): AppLibraryAuditMonitor
    {
        return new AppLibraryAuditMonitor(
            $this->events,
            new ServerApplicationIdResolver(serverIdMap: ['srv' => 1], applicationIdMap: ['shop' => 2]),
            $this->states,
            new NullLogger(),
        );
    }

    /**
     * @param list<array<string,mixed>> $advisories
     * @param null|array<string,mixed>  $app
     */
    private function collector(array $advisories, ?array $app = null): ServerDataCollectorInterface&Stub
    {
        $collector = $this->createStub(ServerDataCollectorInterface::class);
        $collector->method('getApps')->willReturn(['result' => ['shop' => $app ?? self::app()], 'error' => null]);
        $collector->method('fetchAppComposerAudit')->willReturn(self::audit($advisories));

        return $collector;
    }

    /**
     * @param array<string,bool> $monitoring
     *
     * @return array<string,mixed>
     */
    private static function app(array $monitoring = []): array
    {
        return ['composer' => true] + ($monitoring === [] ? [] : ['monitoring' => $monitoring]);
    }

    /**
     * @param list<array<string,mixed>> $advisories
     *
     * @return array<string,mixed>
     */
    private static function audit(array $advisories): array
    {
        return ['result' => ['status' => 'ok', 'advisories' => $advisories], 'error' => null];
    }

    /** @return array<string,mixed> */
    private static function advisory(string $id, string $package, string $severity): array
    {
        return ['package' => $package, 'advisory_id' => $id, 'cve' => null, 'title' => 'Issue', 'severity' => $severity];
    }
}
