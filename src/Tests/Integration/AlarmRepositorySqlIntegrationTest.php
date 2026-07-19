<?php

declare(strict_types=1);

namespace Mata\Tests\Integration;

use DateTimeImmutable;
use Mata\Models\AlarmDeliveryRepository;
use Mata\Models\AlarmRepository;
use Mata\Tests\Helpers\SqliteTestDatabase;
use Mata\Tests\TestCase;
use stdClass;

/**
 * @internal
 *
 * @covers \Mata\Models\AlarmDeliveryRepository
 * @covers \Mata\Models\AlarmRepository
 */
final class AlarmRepositorySqlIntegrationTest extends TestCase
{
    private SqliteTestDatabase $db;
    private AlarmRepository $repository;
    private AlarmDeliveryRepository $deliveryRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = new SqliteTestDatabase();
        $this->createSchema();
        $this->seedData();
        $this->repository = new AlarmRepository($this->db);
        $this->deliveryRepository = new AlarmDeliveryRepository($this->db);
    }

    public function testGetLastAlarmsByContextExecutesSelectWithAppEnv(): void
    {
        $rows = $this->repository->getLastByContext('cpu', 'CPU high', 1, 10, 0);

        $this->assertCount(1, $rows);
        $this->assertSame('server-a', $rows[0]['server']);
        $this->assertSame('app-a', $rows[0]['app']);
        $this->assertSame('prod', $rows[0]['app_env']);
    }

    public function testGetLatestAlarmsExecutesSelectWithAppEnv(): void
    {
        $rows = $this->repository->getLatest(10);

        $this->assertCount(1, $rows);
        $this->assertSame('server-a', $rows[0]['server']);
        $this->assertSame('app-a', $rows[0]['app']);
        $this->assertSame('prod', $rows[0]['app_env']);
        $this->assertSame('sent', $rows[0]['deliveries']['mail']['status']);
    }

    public function testGetLatestBuildsOneRowWithPairedDeliveries(): void
    {
        $alarmId = $this->repository->createForEvent(2);
        $this->deliveryRepository->enqueue($alarmId, 'email', 10);
        $this->deliveryRepository->enqueue($alarmId, 'slack', 10);
        $this->db->pdo()->exec(<<<SQL
            UPDATE alarm_deliveries
            SET status = 'sent', attempts = 1, processed_at = '2026-01-01 00:03:00'
            WHERE alarm_id = {$alarmId} AND channel = 'email';

            UPDATE alarm_deliveries
            SET status = 'retry', attempts = 2, processed_at = '2026-01-01 00:03:01',
                next_retry_at = '2026-01-01 00:08:00'
            WHERE alarm_id = {$alarmId} AND channel = 'slack';
            SQL);

        $this->db->resetQueryLog();
        $rows = $this->repository->getLatest(2);

        $this->assertCount(2, $rows);
        $this->assertSame($alarmId, (int) $rows[0]['id']);
        $this->assertSame('sent', $rows[0]['deliveries']['email']['status']);
        $this->assertSame('retry', $rows[0]['deliveries']['slack']['status']);
        $this->assertSame(2, $rows[0]['deliveries']['slack']['attempts']);
        $this->assertSame('2026-01-01 00:03:01', $rows[0]['deliveries']['slack']['processed_at']);
        $this->assertSame('2026-01-01 00:08:00', $rows[0]['deliveries']['slack']['next_retry_at']);

        $queries = $this->db->queryLog();
        $this->assertCount(2, $queries);
        $this->assertStringContainsString('FROM alarm_deliveries', $queries[1]['sql']);
        $this->assertSame([(int) $rows[0]['id'], (int) $rows[1]['id']], $queries[1]['params']);
    }

    public function testGetLatestKeepsNoDeliveriesAsJsonObjectAndSkipsSecondQueryWhenEmpty(): void
    {
        $this->db->pdo()->exec('DELETE FROM alarm_deliveries; DELETE FROM alarms;');
        $this->db->resetQueryLog();

        $this->assertSame([], $this->repository->getLatest());
        $this->assertCount(1, $this->db->queryLog());

        $alarmId = $this->repository->createForEvent(2);
        $rows = $this->repository->getLatest();

        $this->assertSame($alarmId, (int) $rows[0]['id']);
        $this->assertInstanceOf(stdClass::class, $rows[0]['deliveries']);
        $this->assertSame('{"deliveries":{}}', json_encode(['deliveries' => $rows[0]['deliveries']]));
    }

    public function testGetLatestLimitsAndFiltersLogicalAlarmsBeforeLoadingDeliveries(): void
    {
        $alarmId = $this->repository->createForEvent(2);
        $this->deliveryRepository->enqueue($alarmId, 'email', 10);
        $this->deliveryRepository->enqueue($alarmId, 'slack', 10);

        $rows = $this->repository->getLatest(1);
        $this->assertCount(1, $rows);
        $this->assertSame($alarmId, (int) $rows[0]['id']);
        $this->assertCount(2, $rows[0]['deliveries']);

        $this->assertTrue($this->repository->acknowledge($alarmId, 'operator'));
        $unacknowledged = $this->repository->getLatest(1, true);

        $this->assertCount(1, $unacknowledged);
        $this->assertSame(100, (int) $unacknowledged[0]['id']);
        $this->assertSame('sent', $unacknowledged[0]['deliveries']['mail']['status']);
    }

    public function testLogicalAlarmCountsOnceWithTwoDeliveries(): void
    {
        $alarmId = $this->repository->createForEvent(2);
        $this->deliveryRepository->enqueue($alarmId, 'email', 10);
        $this->deliveryRepository->enqueue($alarmId, 'slack', 10);

        $this->assertSame($alarmId, $this->repository->createForEvent(2));
        $this->assertCount(2, $this->repository->getLatest(2));
        $this->assertEqualsCanonicalizing(
            ['critical' => 1, 'warning' => 1],
            $this->repository->getUnacknowledgedCounts()
        );
    }

    public function testPendingClaimsUseLogicalAlarmEventPayload(): void
    {
        $alarmId = $this->repository->createForEvent(2);
        $this->deliveryRepository->enqueue($alarmId, 'email', 10);

        $deliveries = $this->deliveryRepository->claimAvailable(new DateTimeImmutable('2026-01-01 00:03:00'));

        $this->assertCount(1, $deliveries);
        $this->assertSame(2, $deliveries[0]->eventId);
        $this->assertSame('critical', $deliveries[0]->level);
        $this->assertSame('prod', $deliveries[0]->appEnvironment);
        $this->assertSame('email', $deliveries[0]->channel);
    }

    public function testPendingClaimIncludesPreviousAlarmForTheSameTypeAndTarget(): void
    {
        $this->db->pdo()->exec(<<<'SQL'
            INSERT INTO events (id, server_id, application_id, type, message, context, level, datetime)
            VALUES (3, 1, 10, 'disk', 'Disk full again', '', 'critical', '2026-01-01 00:03:00');
            INSERT INTO alarms (id, event_id, created_at)
            VALUES (101, 2, '2026-01-01 00:04:00'), (102, 3, '2026-01-01 00:05:00');
            INSERT INTO alarm_deliveries (id, alarm_id, channel, status, created_at)
            VALUES (201, 102, 'email', 'pending', '2026-01-01 00:05:00');
            SQL);

        $deliveries = $this->deliveryRepository->claimAvailable(new DateTimeImmutable('2026-01-01 00:06:00'));

        $this->assertCount(1, $deliveries);
        $this->assertSame(102, $deliveries[0]->alarmId);
        $this->assertSame('2026-01-01 00:04:00', $deliveries[0]->previousAlarmCreatedAt?->format('Y-m-d H:i:s'));
    }

    public function testClaimAvailableOnlyClaimsQueueableDeliveriesThatAreDue(): void
    {
        $this->db->pdo()->exec(<<<'SQL'
            INSERT INTO alarms (id, event_id, created_at) VALUES
                (101, 3, '2026-01-01 00:02:00'),
                (102, 4, '2026-01-01 00:02:00'),
                (103, 5, '2026-01-01 00:02:00'),
                (104, 6, '2026-01-01 00:02:00'),
                (105, 7, '2026-01-01 00:02:00'),
                (106, 8, '2026-01-01 00:02:00'),
                (107, 9, '2026-01-01 00:02:00'),
                (108, 10, '2026-01-01 00:02:00'),
                (109, 11, '2026-01-01 00:02:00'),
                (110, 12, '2026-01-01 00:02:00');

            INSERT INTO alarm_deliveries (id, alarm_id, channel, status, next_retry_at, created_at) VALUES
                (201, 101, 'email', 'pending', NULL, '2026-01-01 00:02:00'),
                (202, 102, 'email', 'pending', '2026-01-01 00:04:00', '2026-01-01 00:02:00'),
                (203, 103, 'email', 'pending', '2026-01-01 00:03:00', '2026-01-01 00:02:00'),
                (204, 104, 'email', 'retry', '2026-01-01 00:04:00', '2026-01-01 00:02:00'),
                (205, 105, 'email', 'retry', '2026-01-01 00:03:00', '2026-01-01 00:02:00'),
                (206, 106, 'email', 'processing', NULL, '2026-01-01 00:02:00'),
                (207, 107, 'email', 'sent', NULL, '2026-01-01 00:02:00'),
                (208, 108, 'email', 'failed', NULL, '2026-01-01 00:02:00'),
                (209, 109, 'email', 'throttled', NULL, '2026-01-01 00:02:00'),
                (210, 110, 'email', 'discarded', NULL, '2026-01-01 00:02:00');
            SQL);

        $deliveries = $this->deliveryRepository->claimAvailable(new DateTimeImmutable('2026-01-01 00:03:00'));

        $this->assertSame([201, 203, 205], array_map(static fn ($delivery): int => $delivery->id, $deliveries));
        $rows = $this->db->pdo()->query(
            'SELECT id, status FROM alarm_deliveries WHERE id BETWEEN 201 AND 210 ORDER BY id'
        )->fetchAll();
        $statuses = array_column($rows, 'status', 'id');
        $this->assertSame('processing', $statuses[201]);
        $this->assertSame('pending', $statuses[202]);
        $this->assertSame('processing', $statuses[203]);
        $this->assertSame('retry', $statuses[204]);
        $this->assertSame('processing', $statuses[205]);
        $this->assertSame('processing', $statuses[206]);
        $this->assertSame('sent', $statuses[207]);
        $this->assertSame('failed', $statuses[208]);
        $this->assertSame('throttled', $statuses[209]);
        $this->assertSame('discarded', $statuses[210]);
    }

    public function testAcknowledgementPreservesFirstUserAndBulkSkipsAcknowledged(): void
    {
        $this->assertTrue($this->repository->acknowledge(100, 'first'));
        $this->assertFalse($this->repository->acknowledge(100, 'second'));
        $row = $this->db->pdo()->query('SELECT acknowledged_by FROM alarms WHERE id = 100')->fetch();
        $this->assertSame('first', $row['acknowledged_by']);
        $this->assertSame(0, $this->repository->acknowledgeAll('bulk'));
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

            CREATE TABLE alarm_deliveries (
                id INTEGER PRIMARY KEY,
                alarm_id INTEGER NOT NULL,
                channel TEXT NOT NULL,
                status TEXT NOT NULL,
                priority INTEGER NOT NULL DEFAULT 100,
                attempts INTEGER NOT NULL DEFAULT 0,
                processed_at TEXT,
                next_retry_at TEXT,
                error_payload TEXT,
                created_at TEXT NOT NULL,
                UNIQUE (alarm_id, channel)
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

            INSERT INTO alarms (id, event_id, created_at)
            VALUES (100, 1, '2026-01-01 00:02:00');

            INSERT INTO alarm_deliveries (id, alarm_id, channel, status, created_at)
            VALUES (200, 100, 'mail', 'sent', '2026-01-01 00:02:00');
            SQL);
    }
}
