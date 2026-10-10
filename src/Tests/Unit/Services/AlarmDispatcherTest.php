<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use DateTimeImmutable;
use Mata\Config\Dto\MailConfig;
use Mata\Config\Monitoring\Section\AlarmRateLimit;
use Mata\Config\Monitoring\Section\AlarmRateLimitsConfig;
use Mata\Models\AlarmDeliveryRepository;
use Mata\Services\Alarm\Delivery\AlarmChannelSenderInterface;
use Mata\Services\Alarm\Delivery\AlarmDispatcher;
use Mata\Services\Alarm\Delivery\AlarmEmailFactory;
use Mata\Services\Alarm\Delivery\AlarmEmailSender;
use Mata\Services\Alarm\Delivery\Dto\AlarmDeliveryView;
use Mata\Tests\Helpers\SqliteTestDatabase;
use Mata\Tests\TestCase;
use RuntimeException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * @covers \Mata\Services\Alarm\Delivery\AlarmDispatcher
 *
 * @internal
 */
final class AlarmDispatcherTest extends TestCase
{
    public function testRateLimitRejectionKeepsDeliveryPendingUntilItsRetryTime(): void
    {
        $db = new SqliteTestDatabase();
        $db->pdo()->exec(<<<'SQL'
            CREATE TABLE servers (id INTEGER PRIMARY KEY, name TEXT NOT NULL);
            CREATE TABLE applications (id INTEGER PRIMARY KEY, code TEXT NOT NULL, display_name TEXT NOT NULL, app_env TEXT);
            CREATE TABLE events (
                id INTEGER PRIMARY KEY,
                server_id INTEGER,
                application_id INTEGER,
                type TEXT NOT NULL,
                message TEXT NOT NULL,
                level TEXT NOT NULL,
                context TEXT,
                datetime TEXT NOT NULL
            );
            CREATE TABLE alarms (id INTEGER PRIMARY KEY, event_id INTEGER NOT NULL, trigger_evidence TEXT NULL, created_at TEXT NOT NULL);
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
            INSERT INTO events (id, server_id, application_id, type, message, level, context, datetime) VALUES
                (1, 1, 1, 'disk', 'Disk full', 'critical', NULL, '2025-12-31 23:58:00'),
                (2, 1, 1, 'disk', 'Disk full', 'critical', NULL, '2026-01-01 00:00:05');
            INSERT INTO alarms (id, event_id, created_at) VALUES
                (1, 1, '2026-01-01 00:00:00'),
                (2, 2, '2026-01-01 00:00:10');
            INSERT INTO alarm_deliveries
                (id, alarm_id, channel, status, attempts, processed_at, created_at)
            VALUES
                (1, 1, 'email', 'sent', 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00'),
                (2, 2, 'email', 'pending', 3, NULL, '2026-01-01 00:00:00');
            SQL);

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->never())->method('send');
        $limit = new AlarmRateLimit(perMinute: 1, perHour: 0, perDay: 0);
        $dispatcher = new AlarmDispatcher(
            new AlarmDeliveryRepository($db),
            new AlarmRateLimitsConfig($limit, ['email' => $limit], []),
            $this->senders($mailer)
        );

        $dispatcher->dispatch(new DateTimeImmutable('2026-01-01 00:00:30'));

        $delivery = $db->pdo()->query(
            'SELECT status, attempts, next_retry_at FROM alarm_deliveries WHERE id = 2'
        )->fetch();
        $this->assertSame('pending', $delivery['status']);
        $this->assertSame(3, (int) $delivery['attempts']);
        $this->assertSame('2026-01-01 00:01:30', $delivery['next_retry_at']);
    }

    public function testStaleProcessingDeliveryIsReclaimedWhileFreshOneIsLeftAlone(): void
    {
        $db = $this->createPendingDatabase();
        // Delivery 7 stays pending. Add a delivery stuck in 'processing' since a
        // dispatcher crash two hours ago, plus one claimed moments ago by a
        // (hypothetical) still-running worker.
        $db->pdo()->exec(<<<'SQL'
            INSERT INTO events (id, server_id, application_id, type, message, level, context, datetime)
            VALUES (2, 1, 1, 'cpu', 'CPU high', 'critical', NULL, '2025-12-31 21:57:00'), (3, 1, 1, 'ram', 'RAM high', 'critical', NULL, '2025-12-31 23:57:00');
            INSERT INTO alarms (id, event_id, created_at)
            VALUES (43, 2, '2025-12-31 21:58:00'), (44, 3, '2025-12-31 23:58:30');
            INSERT INTO alarm_deliveries (id, alarm_id, channel, status, attempts, processed_at, created_at)
            VALUES
                (8, 43, 'email', 'processing', 0, '2025-12-31 22:00:00', '2025-12-31 21:59:00'),
                (9, 44, 'email', 'processing', 0, '2025-12-31 23:59:00', '2025-12-31 23:58:30');
            SQL);
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->exactly(2))->method('send');
        $limit = new AlarmRateLimit(0, 0, 0);
        $dispatcher = new AlarmDispatcher(
            new AlarmDeliveryRepository($db),
            new AlarmRateLimitsConfig($limit, ['email' => $limit], []),
            $this->senders($mailer),
        );

        $dispatcher->dispatch(new DateTimeImmutable('2026-01-01 00:00:00'));

        $rows = $db->pdo()->query(
            'SELECT id, status, attempts FROM alarm_deliveries ORDER BY id'
        )->fetchAll();
        $byId = array_column($rows, null, 'id');
        $this->assertSame('sent', $byId[7]['status'], 'pending delivery must be dispatched');
        $this->assertSame('sent', $byId[8]['status'], 'stale processing delivery must be reclaimed and dispatched');
        $this->assertSame(1, (int) $byId[8]['attempts']);
        $this->assertSame('processing', $byId[9]['status'], 'freshly claimed delivery must not be double-processed');
        $this->assertSame(0, (int) $byId[9]['attempts']);
    }

    public function testSuccessfulEmailSendMarksDeliverySentAndPassesAlarmMetadata(): void
    {
        $db = $this->createPendingDatabase();
        $sent = null;
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->once())->method('send')->willReturnCallback(
            static function (RawMessage $message) use (&$sent): void {
                $sent = $message;
            },
        );
        $limit = new AlarmRateLimit(0, 0, 0);
        $dispatcher = new AlarmDispatcher(
            new AlarmDeliveryRepository($db),
            new AlarmRateLimitsConfig($limit, ['email' => $limit], []),
            $this->senders($mailer),
        );

        $dispatcher->dispatch(new DateTimeImmutable('2026-01-01 00:00:00'));

        $delivery = $db->pdo()->query('SELECT status, attempts FROM alarm_deliveries WHERE id = 7')->fetch();
        $this->assertSame('sent', $delivery['status']);
        $this->assertSame(1, (int) $delivery['attempts']);
        $this->assertInstanceOf(Email::class, $sent);
        $html = (string) $sent->getHtmlBody();
        $this->assertStringContainsString('Alarm ID: 42', $html);
        $this->assertStringContainsString('Alarm time: 2025-12-31 23:59:00 UTC', $html);
        $this->assertStringContainsString('Event ID: 1', $html);
        $this->assertStringContainsString('Event time: 2025-12-31 23:58:00 UTC', $html);
        $this->assertStringContainsString('Alarm rule: 2 consecutive failed cycles within 10 minutes', $html);
    }

    public function testFactoryFailureMovesDeliveryFromRetryToFailedAtMaxAttempts(): void
    {
        $db = $this->createPendingDatabase();
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->never())->method('send');
        $limit = new AlarmRateLimit(0, 0, 0);
        $dispatcher = new AlarmDispatcher(
            new AlarmDeliveryRepository($db),
            new AlarmRateLimitsConfig($limit, ['email' => $limit], []),
            $this->senders($mailer, false),
            maxAttempts: 2,
        );

        $dispatcher->dispatch(new DateTimeImmutable('2026-01-01 00:00:00'));
        $retry = $db->pdo()->query('SELECT status, attempts FROM alarm_deliveries WHERE id = 7')->fetch();
        $this->assertSame('retry', $retry['status']);
        $this->assertSame(1, (int) $retry['attempts']);

        $dispatcher->dispatch(new DateTimeImmutable('2026-01-01 00:01:00'));
        $failed = $db->pdo()->query('SELECT status, attempts FROM alarm_deliveries WHERE id = 7')->fetch();
        $this->assertSame('failed', $failed['status']);
        $this->assertSame(2, (int) $failed['attempts']);
    }

    public function testFailingWebhookChannelRetriesWhileEmailForTheSameAlarmIsSent(): void
    {
        $db = $this->createPendingDatabase();
        $db->pdo()->exec(<<<'SQL'
            INSERT INTO alarm_deliveries (id, alarm_id, channel, status, created_at) VALUES (8, 42, 'telegram', 'pending', '2026-01-01 00:00:00');
            SQL);
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->once())->method('send');
        $telegram = $this->createMock(AlarmChannelSenderInterface::class);
        $telegram->expects($this->once())->method('send')
            ->with($this->callback(static fn (AlarmDeliveryView $view): bool => $view->alarmId === 42 && $view->alarm->channels === ['telegram']))
            ->willThrowException(new RuntimeException('Webhook channel telegram (api.telegram.org) returned HTTP 502: Bad Gateway'));
        $limit = new AlarmRateLimit(0, 0, 0);
        $dispatcher = new AlarmDispatcher(
            new AlarmDeliveryRepository($db),
            new AlarmRateLimitsConfig($limit, [], []),
            $this->senders($mailer, extra: ['telegram' => $telegram]),
        );

        $dispatcher->dispatch(new DateTimeImmutable('2026-01-01 00:00:00'));

        $rows = array_column($db->pdo()->query(
            'SELECT id, status, attempts, error_payload FROM alarm_deliveries ORDER BY id'
        )->fetchAll(), null, 'id');
        $this->assertSame('sent', $rows[7]['status']);
        $this->assertSame('retry', $rows[8]['status']);
        $this->assertSame(1, (int) $rows[8]['attempts']);
        $this->assertStringContainsString('returned HTTP 502', (string) $rows[8]['error_payload']);
    }

    public function testDeliveryForAnUnconfiguredChannelIsRetriedWithAnExplicitError(): void
    {
        $db = $this->createPendingDatabase();
        $db->pdo()->exec("UPDATE alarm_deliveries SET channel = 'slack' WHERE id = 7");
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->never())->method('send');
        $limit = new AlarmRateLimit(0, 0, 0);
        $dispatcher = new AlarmDispatcher(
            new AlarmDeliveryRepository($db),
            new AlarmRateLimitsConfig($limit, [], []),
            $this->senders($mailer),
        );

        $dispatcher->dispatch(new DateTimeImmutable('2026-01-01 00:00:00'));

        $delivery = $db->pdo()->query('SELECT status, error_payload FROM alarm_deliveries WHERE id = 7')->fetch();
        $this->assertSame('retry', $delivery['status']);
        $this->assertStringContainsString('Unsupported alarm delivery channel: slack', (string) $delivery['error_payload']);
    }

    /**
     * @param array<string,AlarmChannelSenderInterface> $extra
     *
     * @return array<string,AlarmChannelSenderInterface>
     */
    private function senders(MailerInterface $mailer, bool $configured = true, array $extra = []): array
    {
        return ['email' => $this->emailSender($mailer, $configured)] + $extra;
    }

    private function emailSender(MailerInterface $mailer, bool $configured): AlarmEmailSender
    {
        $template = <<<'TWIG'
            Alarm ID: {{ alarm.id }}
            Alarm time: {{ alarm.created_at }}
            Event ID: {{ alarm.event_id }}
            Event time: {{ alarm.event_occurred_at }}
            {{ presentation.metric.detail }}
            TWIG;

        $factory = new AlarmEmailFactory(
            new Environment(new ArrayLoader([
                'emails/alarm.html.twig' => $template,
                'emails/alarm.txt.twig' => $template,
            ]), ['autoescape' => 'name']),
            new MailConfig(
                null,
                $configured ? 'alerts@example.com' : null,
                'Alerts',
                $configured ? ['ops@example.com'] : [],
                [],
                [],
                'UTF-8',
                null,
            ),
            'MATA',
        );

        return new AlarmEmailSender($mailer, $factory);
    }

    private function createPendingDatabase(): SqliteTestDatabase
    {
        $db = new SqliteTestDatabase();
        $db->pdo()->exec(<<<'SQL'
            CREATE TABLE servers (id INTEGER PRIMARY KEY, name TEXT NOT NULL);
            CREATE TABLE applications (id INTEGER PRIMARY KEY, code TEXT NOT NULL, display_name TEXT NOT NULL, app_env TEXT);
            CREATE TABLE events (id INTEGER PRIMARY KEY, server_id INTEGER, application_id INTEGER, type TEXT NOT NULL, message TEXT NOT NULL, level TEXT NOT NULL, context TEXT, datetime TEXT NOT NULL);
            CREATE TABLE alarms (id INTEGER PRIMARY KEY, event_id INTEGER NOT NULL, trigger_evidence TEXT NULL, created_at TEXT NOT NULL);
            CREATE TABLE alarm_deliveries (id INTEGER PRIMARY KEY, alarm_id INTEGER NOT NULL, channel TEXT NOT NULL, status TEXT NOT NULL, priority INTEGER NOT NULL DEFAULT 100, attempts INTEGER NOT NULL DEFAULT 0, processed_at TEXT, next_retry_at TEXT, error_payload TEXT, created_at TEXT NOT NULL);
            INSERT INTO servers (id, name) VALUES (1, 'server-a');
            INSERT INTO applications (id, code, display_name) VALUES (1, 'app-a', 'App A');
            INSERT INTO events (id, server_id, application_id, type, message, level, context, datetime) VALUES (1, 1, 1, 'app_unreachable', 'App unavailable', 'critical', NULL, '2025-12-31 23:58:00');
            INSERT INTO alarms (id, event_id, trigger_evidence, created_at) VALUES (42, 1, '{"strategy":"consecutive_failures","required_count":2,"observed_count":2,"window_minutes":10,"label":"2 consecutive failed cycles within 10 minutes"}', '2025-12-31 23:59:00');
            INSERT INTO alarm_deliveries (id, alarm_id, channel, status, created_at) VALUES (7, 42, 'email', 'pending', '2026-01-01 00:00:00');
            SQL);

        return $db;
    }
}
