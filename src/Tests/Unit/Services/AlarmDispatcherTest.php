<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use DateTimeImmutable;
use Mata\Config\Dto\AlarmLimitsConfig;
use Mata\Core\DatabaseInterface;
use Mata\Models\AlarmDeliveryRepository;
use Mata\Services\Alarm\AlarmDispatcher;
use Mata\Services\Alarm\AlarmEmailSender;
use Mata\Services\Alarm\Dto\AlarmLimit;
use Mata\Tests\TestCase;
use PDO;
use Symfony\Component\Mailer\MailerInterface;

/**
 * @covers \Mata\Services\Alarm\AlarmDispatcher
 *
 * @internal
 */
final class AlarmDispatcherTest extends TestCase
{
    public function testRateLimitRejectionKeepsDeliveryPendingUntilItsRetryTime(): void
    {
        $db = new SqliteAlarmDispatcherDatabase();
        $db->pdo()->exec(<<<'SQL'
            CREATE TABLE servers (id INTEGER PRIMARY KEY, name TEXT NOT NULL);
            CREATE TABLE applications (id INTEGER PRIMARY KEY, code TEXT NOT NULL, display_name TEXT NOT NULL);
            CREATE TABLE events (
                id INTEGER PRIMARY KEY,
                server_id INTEGER,
                application_id INTEGER,
                type TEXT NOT NULL,
                message TEXT NOT NULL,
                level TEXT NOT NULL,
                context TEXT
            );
            CREATE TABLE alarms (id INTEGER PRIMARY KEY, event_id INTEGER NOT NULL);
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
                created_at TEXT NOT NULL
            );

            INSERT INTO servers (id, name) VALUES (1, 'server-a');
            INSERT INTO applications (id, code, display_name) VALUES (1, 'app-a', 'App A');
            INSERT INTO events (id, server_id, application_id, type, message, level, context) VALUES
                (1, 1, 1, 'disk', 'Disk full', 'critical', NULL),
                (2, 1, 1, 'disk', 'Disk full', 'critical', NULL);
            INSERT INTO alarms (id, event_id) VALUES (1, 1), (2, 2);
            INSERT INTO alarm_deliveries
                (id, alarm_id, channel, status, attempts, processed_at, created_at)
            VALUES
                (1, 1, 'email', 'sent', 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00'),
                (2, 2, 'email', 'pending', 3, NULL, '2026-01-01 00:00:00');
            SQL);

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->never())->method('send');
        $limit = new AlarmLimit(perMinute: 1, perHour: 0, perDay: 0);
        $dispatcher = new AlarmDispatcher(
            new AlarmDeliveryRepository($db),
            new AlarmLimitsConfig($limit, ['email' => $limit]),
            new AlarmEmailSender($mailer, [])
        );

        $dispatcher->dispatch(new DateTimeImmutable('2026-01-01 00:00:30'));

        $delivery = $db->pdo()->query(
            'SELECT status, attempts, next_retry_at FROM alarm_deliveries WHERE id = 2'
        )->fetch();
        $this->assertSame('pending', $delivery['status']);
        $this->assertSame(3, (int) $delivery['attempts']);
        $this->assertSame('2026-01-01 00:01:30', $delivery['next_retry_at']);
    }
}

final class SqliteAlarmDispatcherDatabase implements DatabaseInterface
{
    private PDO $pdo;

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

    public function runQuery(string $sql, array $params = []): array|int
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $type = strtoupper((string) strtok(trim($sql), ' '));

        return match ($type) {
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
