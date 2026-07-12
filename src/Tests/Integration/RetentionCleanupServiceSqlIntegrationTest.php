<?php

declare(strict_types=1);

namespace Mata\Tests\Integration;

use DateTimeImmutable;
use Mata\Core\DatabaseInterface;
use Mata\Services\Retention\RetentionCleanupResult;
use Mata\Services\Retention\RetentionCleanupService;
use Mata\Tests\TestCase;
use PDO;
use PDOException;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @internal
 *
 * @covers \Mata\Services\Retention\RetentionCleanupService
 */
final class RetentionCleanupServiceSqlIntegrationTest extends TestCase
{
    private const NOW = '2026-07-12 12:00:00';

    private SqliteRetentionDatabase $db;
    private RetentionCleanupService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = new SqliteRetentionDatabase();
        $this->createSchema();
        $this->service = new RetentionCleanupService($this->db, new NullLogger());
    }

    public function testOldAlarmsWithTerminalDeliveriesAreDeleted(): void
    {
        foreach (['sent', 'failed', 'throttled', 'discarded'] as $index => $status) {
            $id = $index + 1;
            $this->addEvent($id, '2025-01-01 00:00:00');
            $this->addAlarm($id, $id, '2025-01-01 00:00:00');
            $this->addDelivery($id, $id, $status);
        }

        $result = $this->cleanup(['alarms' => ['retention_days' => 30]]);

        $this->assertSame(4, $result->alarmsDeleted);
        $this->assertSame(0, $this->countRows('alarms'));
        $this->assertSame(0, $this->countRows('alarm_deliveries'));
    }

    public function testOldAlarmsWithProtectedDeliveriesAreRetained(): void
    {
        foreach (['pending', 'processing', 'retry'] as $index => $status) {
            $id = $index + 1;
            $this->addEvent($id, '2025-01-01 00:00:00');
            $this->addAlarm($id, $id, '2025-01-01 00:00:00');
            $this->addDelivery($id, $id, $status);
        }

        $result = $this->cleanup(['alarms' => ['retention_days' => 30]]);

        $this->assertSame(0, $result->alarmsDeleted);
        $this->assertSame(3, $this->countRows('alarms'));
    }

    public function testOldZeroDeliveryAlarmIsDeletedAndFreshAlarmIsRetained(): void
    {
        $this->addEvent(1, '2025-01-01 00:00:00');
        $this->addAlarm(1, 1, '2025-01-01 00:00:00');
        $this->addEvent(2, '2026-07-11 00:00:00');
        $this->addAlarm(2, 2, '2026-07-11 00:00:00');

        $result = $this->cleanup(['alarms' => ['retention_days' => 30]]);

        $this->assertSame(1, $result->alarmsDeleted);
        $this->assertSame([2], $this->ids('alarms'));
    }

    public function testExpiredEventReferencedByRetainedAlarmIsRetained(): void
    {
        $this->addEvent(1, '2025-01-01 00:00:00');
        $this->addAlarm(1, 1, '2025-01-01 00:00:00');
        $this->addDelivery(1, 1, 'pending');

        $result = $this->cleanup([
            'alarms' => ['retention_days' => 30],
            'events' => ['retention_days' => 30],
        ]);

        $this->assertSame(0, $result->eventsDeleted);
        $this->assertSame([1], $this->ids('events'));
    }

    public function testExpiredUnreferencedEventIsDeleted(): void
    {
        $this->addEvent(1, '2025-01-01 00:00:00');

        $result = $this->cleanup(['events' => ['retention_days' => 30]]);

        $this->assertSame(1, $result->eventsDeleted);
        $this->assertSame(0, $this->countRows('events'));
    }

    public function testExpiredEventBecomesDeletableAfterItsAlarmIsDeleted(): void
    {
        $this->addEvent(1, '2025-01-01 00:00:00');
        $this->addAlarm(1, 1, '2025-01-01 00:00:00');

        $result = $this->cleanup([
            'alarms' => ['retention_days' => 30],
            'events' => ['retention_days' => 30],
        ]);

        $this->assertSame(1, $result->alarmsDeleted);
        $this->assertSame(1, $result->eventsDeleted);
        $this->assertSame(0, $this->countRows('events'));
    }

    public function testForeignKeyRejectsDirectDeletionOfReferencedEvent(): void
    {
        $this->addEvent(1, '2025-01-01 00:00:00');
        $this->addAlarm(1, 1, '2025-01-01 00:00:00');

        $this->expectException(PDOException::class);
        $this->db->pdo()->exec('DELETE FROM events WHERE id = 1');
    }

    public function testMetricsRetentionAppliesToServerAndAppTablesAtEveryTier(): void
    {
        $tables = [
            'server_metrics_raw' => ['column' => 'collected_at', 'old' => '2026-06-01 00:00:00', 'new' => '2026-07-11 00:00:00'],
            'app_metrics_raw' => ['column' => 'collected_at', 'old' => '2026-06-01 00:00:00', 'new' => '2026-07-11 00:00:00'],
            'server_metrics_hourly' => ['column' => 'hour_timestamp', 'old' => '2026-03-01 00:00:00', 'new' => '2026-07-11 00:00:00'],
            'app_metrics_hourly' => ['column' => 'hour_timestamp', 'old' => '2026-03-01 00:00:00', 'new' => '2026-07-11 00:00:00'],
            'server_metrics_daily' => ['column' => 'date', 'old' => '2025-01-01', 'new' => '2026-07-11'],
            'app_metrics_daily' => ['column' => 'date', 'old' => '2025-01-01', 'new' => '2026-07-11'],
        ];
        foreach ($tables as $table => $definition) {
            $this->addMetric($table, $definition['column'], 1, $definition['old']);
            $this->addMetric($table, $definition['column'], 2, $definition['new']);
        }

        $result = $this->cleanup([
            'metrics' => [
                'raw_retention_days' => 30,
                'hourly_retention_days' => 90,
                'daily_retention_days' => 365,
            ],
        ]);

        foreach (array_keys($tables) as $table) {
            $this->assertSame(1, $result->metricDeleted($table));
            $this->assertSame([2], $this->ids($table));
        }
    }

    public function testDisabledCategoriesPerformNoDeleteQueries(): void
    {
        $this->addEvent(1, '2025-01-01 00:00:00');
        $this->addAlarm(1, 1, '2025-01-01 00:00:00');
        $this->addMetric('server_metrics_raw', 'collected_at', 1, '2025-01-01 00:00:00');
        $this->db->resetQueryLog();

        $result = $this->cleanup([]);

        $this->assertSame([], $this->db->queryLog());
        $this->assertSame(0, $result->alarmsDeleted);
        $this->assertSame(0, $result->eventsDeleted);
        $this->assertSame(0, $result->metricDeleted('server_metrics_raw'));
    }

    public function testBatchLimitDrainsTableAcrossMultipleStatements(): void
    {
        for ($id = 1; $id <= 5; ++$id) {
            $this->addEvent($id, '2025-01-01 00:00:00');
            $this->addAlarm($id, $id, sprintf('2025-01-%02d 00:00:00', $id));
        }
        $heartbeats = 0;

        $result = $this->cleanup(
            [
                'alarms' => ['retention_days' => 30],
                'pruning' => ['batch_limit' => 2],
            ],
            static function () use (&$heartbeats): void {
                ++$heartbeats;
            }
        );

        $this->assertSame(5, $result->alarmsDeleted);
        $this->assertSame(0, $this->countRows('alarms'));
        $this->assertCount(3, $this->db->queryLog());
        $this->assertSame(3, $heartbeats);
        foreach ($this->db->queryLog() as $query) {
            $this->assertStringContainsString('DELETE FROM alarms', $query['sql']);
            $this->assertStringContainsString('LIMIT 2', $query['sql']);
        }
    }

    public function testLaterMetricBatchFailureRetainsPartialCountAndContinues(): void
    {
        for ($id = 1; $id <= 5; ++$id) {
            $this->addMetric('server_metrics_raw', 'collected_at', $id, '2025-01-01 00:00:00');
        }
        $this->addMetric('app_metrics_raw', 'collected_at', 1, '2025-01-01 00:00:00');
        $this->db->failOnMatchingQueryAttempt('DELETE FROM server_metrics_raw', 2);

        $result = $this->cleanup([
            'metrics' => ['raw_retention_days' => 30],
            'pruning' => ['batch_limit' => 2],
        ]);

        $this->assertSame(2, $result->metricDeleted('server_metrics_raw'));
        $this->assertSame(1, $result->metricDeleted('app_metrics_raw'));
        $this->assertSame(['server_metrics_raw'], array_column($result->failures, 'category'));
        $this->assertSame([3, 4, 5], $this->ids('server_metrics_raw'));
        $this->assertSame(0, $this->countRows('app_metrics_raw'));
    }

    public function testAlarmFailureBlocksEventsAndMetricFailureDoesNotBlockOtherMetrics(): void
    {
        $this->addEvent(1, '2025-01-01 00:00:00');
        $this->addMetric('server_metrics_raw', 'collected_at', 1, '2025-01-01 00:00:00');
        $this->addMetric('app_metrics_raw', 'collected_at', 1, '2025-01-01 00:00:00');
        $this->db->failWhenSqlContains('DELETE FROM alarms');
        $this->db->failWhenSqlContains('DELETE FROM server_metrics_raw');

        $result = $this->cleanup([
            'alarms' => ['retention_days' => 30],
            'events' => ['retention_days' => 30],
            'metrics' => ['raw_retention_days' => 30],
        ]);

        $this->assertTrue($result->hasFailures());
        $this->assertSame(['alarms', 'server_metrics_raw'], array_column($result->failures, 'category'));
        $this->assertSame([1], $this->ids('events'));
        $this->assertSame(1, $this->countRows('server_metrics_raw'));
        $this->assertSame(0, $this->countRows('app_metrics_raw'));
    }

    public function testEventFailureDoesNotBlockMetricCleanup(): void
    {
        $this->addEvent(1, '2025-01-01 00:00:00');
        $this->addMetric('server_metrics_raw', 'collected_at', 1, '2025-01-01 00:00:00');
        $this->db->failWhenSqlContains('DELETE FROM events');

        $result = $this->cleanup([
            'events' => ['retention_days' => 30],
            'metrics' => ['raw_retention_days' => 30],
        ]);

        $this->assertSame(['events'], array_column($result->failures, 'category'));
        $this->assertSame([1], $this->ids('events'));
        $this->assertSame(0, $this->countRows('server_metrics_raw'));
    }

    /** @param array<string,mixed> $overrides */
    private function cleanup(array $overrides, ?callable $tick = null): RetentionCleanupResult
    {
        $config = array_replace_recursive([
            'metrics' => [
                'raw_retention_days' => 0,
                'hourly_retention_days' => 0,
                'daily_retention_days' => 0,
            ],
            'events' => ['retention_days' => 0],
            'alarms' => ['retention_days' => 0],
            'pruning' => ['batch_limit' => 5000],
        ], $overrides);

        return $this->service->cleanup(new DateTimeImmutable(self::NOW), $config, $tick);
    }

    private function createSchema(): void
    {
        $this->db->pdo()->exec(<<<'SQL'
            PRAGMA foreign_keys = ON;

            CREATE TABLE events (
                id INTEGER PRIMARY KEY,
                datetime TEXT NOT NULL
            );

            CREATE TABLE alarms (
                id INTEGER PRIMARY KEY,
                event_id INTEGER NOT NULL UNIQUE REFERENCES events(id) ON DELETE RESTRICT,
                created_at TEXT NOT NULL
            );

            CREATE TABLE alarm_deliveries (
                id INTEGER PRIMARY KEY,
                alarm_id INTEGER NOT NULL REFERENCES alarms(id) ON DELETE CASCADE,
                status TEXT NOT NULL
            );

            CREATE TABLE server_metrics_raw (id INTEGER PRIMARY KEY, collected_at TEXT NOT NULL);
            CREATE TABLE app_metrics_raw (id INTEGER PRIMARY KEY, collected_at TEXT NOT NULL);
            CREATE TABLE server_metrics_hourly (id INTEGER PRIMARY KEY, hour_timestamp TEXT NOT NULL);
            CREATE TABLE app_metrics_hourly (id INTEGER PRIMARY KEY, hour_timestamp TEXT NOT NULL);
            CREATE TABLE server_metrics_daily (id INTEGER PRIMARY KEY, date TEXT NOT NULL);
            CREATE TABLE app_metrics_daily (id INTEGER PRIMARY KEY, date TEXT NOT NULL);
            SQL);
    }

    private function addEvent(int $id, string $datetime): void
    {
        $statement = $this->db->pdo()->prepare('INSERT INTO events (id, datetime) VALUES (?, ?)');
        $statement->execute([$id, $datetime]);
    }

    private function addAlarm(int $id, int $eventId, string $createdAt): void
    {
        $statement = $this->db->pdo()->prepare('INSERT INTO alarms (id, event_id, created_at) VALUES (?, ?, ?)');
        $statement->execute([$id, $eventId, $createdAt]);
    }

    private function addDelivery(int $id, int $alarmId, string $status): void
    {
        $statement = $this->db->pdo()->prepare('INSERT INTO alarm_deliveries (id, alarm_id, status) VALUES (?, ?, ?)');
        $statement->execute([$id, $alarmId, $status]);
    }

    private function addMetric(string $table, string $column, int $id, string $timestamp): void
    {
        $statement = $this->db->pdo()->prepare("INSERT INTO {$table} (id, {$column}) VALUES (?, ?)");
        $statement->execute([$id, $timestamp]);
    }

    /** @return array<int,int> */
    private function ids(string $table): array
    {
        $rows = $this->db->pdo()->query("SELECT id FROM {$table} ORDER BY id")->fetchAll();

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    private function countRows(string $table): int
    {
        return (int) $this->db->pdo()->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    }
}

final class SqliteRetentionDatabase implements DatabaseInterface
{
    private PDO $pdo;

    /** @var array<int,array{sql:string,params:array<int|string,mixed>}> */
    private array $queryLog = [];

    /** @var array<int,string> */
    private array $failureNeedles = [];

    /** @var array<string,int> */
    private array $failureAttempts = [];

    /** @var array<string,int> */
    private array $queryAttempts = [];

    public function __construct()
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /** @return array<int,array{sql:string,params:array<int|string,mixed>}> */
    public function queryLog(): array
    {
        return $this->queryLog;
    }

    public function resetQueryLog(): void
    {
        $this->queryLog = [];
    }

    public function failWhenSqlContains(string $needle): void
    {
        $this->failureNeedles[] = $needle;
    }

    public function failOnMatchingQueryAttempt(string $needle, int $attempt): void
    {
        $this->failureAttempts[$needle] = $attempt;
    }

    public function runQuery(string $sql, array $params = []): array|int
    {
        $this->queryLog[] = ['sql' => $sql, 'params' => $params];
        foreach ($this->failureNeedles as $needle) {
            if (str_contains($sql, $needle)) {
                throw new RuntimeException("Forced failure for {$needle}");
            }
        }
        foreach ($this->failureAttempts as $needle => $attempt) {
            if (!str_contains($sql, $needle)) {
                continue;
            }

            $this->queryAttempts[$needle] = ($this->queryAttempts[$needle] ?? 0) + 1;
            if ($this->queryAttempts[$needle] === $attempt) {
                throw new RuntimeException("Forced failure for {$needle} on attempt {$attempt}");
            }
        }

        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $firstWord = strtok(trim($sql), ' ');
        $sqlType = strtoupper($firstWord === false ? '' : $firstWord);

        return match ($sqlType) {
            'INSERT' => (int) $this->pdo->lastInsertId(),
            'UPDATE', 'DELETE' => $statement->rowCount(),
            default => $statement->fetchAll(PDO::FETCH_ASSOC),
        };
    }

    public function beginTransaction(): void
    {
        $this->pdo->beginTransaction();
    }

    public function commit(): void
    {
        $this->pdo->commit();
    }

    public function rollback(): void
    {
        $this->pdo->rollBack();
    }
}
