<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Services;

use PHPUnit\Framework\MockObject\MockObject;
use Watcher\Config\Dto\ApplicationConfig;
use Watcher\Config\Dto\DashboardConfig;
use Watcher\Config\Dto\DatabaseConfig;
use Watcher\Config\Dto\EnvironmentConfig;
use Watcher\Config\Dto\GeneralConfig;
use Watcher\Config\Dto\LoggingConfig;
use Watcher\Config\Dto\MailConfig;
use Watcher\Config\Dto\PhpConfig;
use Watcher\Config\Dto\SecretsConfig;
use Watcher\Config\Dto\SmtpDevConfig;
use Watcher\Config\Dto\TemplateConfig;
use Watcher\Models\Dto\EventRecordResult;
use Watcher\Models\EventRecorder;
use Watcher\Services\Foundation\AlertManager;
use Watcher\Services\Foundation\Dto\Alert;
use Watcher\Services\Foundation\Dto\EventContext;
use Watcher\Services\Foundation\EventManager;
use Watcher\Tests\TestCase;

/**
 * @covers \Watcher\Services\Foundation\EventManager
 *
 * @internal
 */
final class EventManagerTest extends TestCase
{
    private EventRecorder&MockObject $eventRecorder;
    private AlertManager&MockObject $alertManager;

    /** @var array<string,mixed> */
    private array $thresholds;

    protected function setUp(): void
    {
        parent::setUp();

        $this->eventRecorder = $this->createMock(EventRecorder::class);
        $this->alertManager = $this->createMock(AlertManager::class);
        $this->thresholds = [
            'alarms' => [
                'test_event' => [
                    'alarm_threshold_event_count' => 1,
                ],
            ],
        ];
    }

    public function testHandleEventSkipsAlarmWhenAlarmsDisabled(): void
    {
        $manager = $this->createManager(alarmsEnabled: false, eventsEnabled: true);

        $this->eventRecorder
            ->expects($this->never())
            ->method('getLastAlarmsByContext')
        ;

        $this->eventRecorder
            ->expects($this->once())
            ->method('hasActiveCooldown')
            ->willReturn(false)
        ;

        $this->eventRecorder
            ->expects($this->once())
            ->method('recordEvent')
            ->willReturn(new EventRecordResult(
                eventId: 1,
                alarmId: null,
                serverId: null,
                appId: null,
                serverLabel: null,
                appLabel: null,
                serverKey: null
            ))
        ;

        $this->alertManager->expects($this->never())->method('handleAlert');

        $result = $manager->handleEvent(
            type: 'test_event',
            message: 'Test message'
        );

        $this->assertFalse($result['alarmSent']);
    }

    public function testShouldCreateEventReturnsFalseWhenEventsDisabled(): void
    {
        $manager = $this->createManager(eventsEnabled: false);

        $this->eventRecorder
            ->expects($this->never())
            ->method('hasActiveCooldown')
        ;

        $hashPayload = [
            'type' => 'test_event',
            'server' => 'srv-1',
            'app' => '',
            'context' => EventContext::empty()->toJson(),
            'channel' => '',
        ];
        $hash = hash('sha256', json_encode($hashPayload, JSON_UNESCAPED_SLASHES));

        $result = $manager->shouldCreateEvent(
            cooldownHash: $hash,
            cooldownMinutes: 30
        );

        $this->assertFalse($result);
    }

    public function testShouldCreateEventRespectsConfiguredCooldown(): void
    {
        $intervalsConfig = [
            'events' => [
                'min_event_interval_minutes' => [
                    'test_event' => 15,
                ],
            ],
            'alarms' => [
                'min_alarm_interval_minutes' => [],
            ],
        ];

        $manager = $this->createManager(intervalsConfig: $intervalsConfig);

        $context = EventContext::fromArray(['detail' => 'context']);
        $expectedHash = hash('sha256', json_encode([
            'type' => 'test_event',
            'server' => 'srv-1',
            'app' => 'app-1',
            'context' => $context->toJson(),
            'channel' => '',  // Empty channel for events
        ], JSON_UNESCAPED_SLASHES));

        $this->eventRecorder
            ->expects($this->once())
            ->method('hasActiveCooldown')
            ->with($expectedHash)
            ->willReturn(true)
        ;

        $result = $manager->shouldCreateEvent(
            cooldownHash: $expectedHash,
            cooldownMinutes: 15
        );

        $this->assertFalse($result);
    }

    public function testHandleEventSkipsWhenPoliciesBlockEverything(): void
    {
        $manager = $this->createManager(eventsEnabled: false, alarmsEnabled: false);

        $this->eventRecorder->expects($this->never())->method('recordEvent');
        $this->alertManager->expects($this->never())->method('handleAlert');

        $result = $manager->handleEvent(
            type: 'test_event',
            message: 'Test message'
        );

        $this->assertSame(
            ['eventRecorded' => false, 'alarmSent' => false, 'eventId' => null, 'alarmId' => null],
            $result
        );
    }

    public function testHandleEventRecordsEventWithoutAlarmWhenOnlyEventsEnabled(): void
    {
        $intervalsConfig = [
            'events' => ['min_event_interval_minutes' => ['_default' => 30]],
            'alarms' => ['min_alarm_interval_minutes' => []],
        ];
        $manager = $this->createManager(eventsEnabled: true, alarmsEnabled: false, intervalsConfig: $intervalsConfig);

        $this->eventRecorder
            ->expects($this->never())
            ->method('getLastAlarmsByContext')
        ;

        $expectedHash = hash('sha256', json_encode([
            'type' => 'test_event',
            'server' => '',
            'app' => '',
            'context' => EventContext::empty()->toJson(),
            'channel' => '',  // Empty channel for events
        ], JSON_UNESCAPED_SLASHES));

        $this->eventRecorder
            ->expects($this->once())
            ->method('hasActiveCooldown')
            ->with($expectedHash)
            ->willReturn(false)
        ;

        $this->eventRecorder
            ->expects($this->once())
            ->method('recordEvent')
            ->with(
                'test_event',
                'Test message',
                '',
                'info',
                null,
                null,
                ['email'],
                false,
                $expectedHash,
                30
            )
            ->willReturn(new EventRecordResult(
                eventId: 123,
                alarmId: null,
                serverId: null,
                appId: null,
                serverLabel: null,
                appLabel: null,
                serverKey: null
            ))
        ;

        $this->alertManager->expects($this->never())->method('handleAlert');

        $result = $manager->handleEvent(
            type: 'test_event',
            message: 'Test message'
        );

        $this->assertSame(
            ['eventRecorded' => true, 'alarmSent' => false, 'eventId' => 123, 'alarmId' => null],
            $result
        );
    }

    public function testHandleEventRecordsEventAndSendsAlarm(): void
    {
        // Note: test_event threshold is alarm_threshold_event_count = 1
        // So any events in the time window will trigger alarm evaluation
        $intervalsConfig = [
            'events' => ['min_event_interval_minutes' => ['_default' => 30]],
            'alarms' => ['min_alarm_interval_minutes' => []],
        ];
        $manager = $this->createManager(eventsEnabled: true, alarmsEnabled: true, intervalsConfig: $intervalsConfig);

        $this->eventRecorder
            ->expects($this->once())
            ->method('getLastEventsByContext')
            ->willReturn([['id' => 1]])  // Return at least one event to meet threshold of 1
        ;

        $this->eventRecorder
            ->expects($this->once())
            ->method('getActiveAlarmCooldowns')
            ->with($this->anything())
            ->willReturn([])  // No active cooldowns, all channels available
        ;

        $context = ['detail' => 'context'];
        $contextDto = EventContext::fromArray($context);
        $contextJson = $contextDto->toJson();
        $expectedHash = hash('sha256', json_encode([
            'type' => 'test_event',
            'server' => 'srv-1',
            'app' => 'app-1',
            'context' => $contextJson,
            'channel' => '',  // Empty channel for events
        ], JSON_UNESCAPED_SLASHES));

        $this->eventRecorder
            ->expects($this->once())
            ->method('hasActiveCooldown')
            ->with($expectedHash)
            ->willReturn(false)
        ;

        $this->eventRecorder
            ->expects($this->once())
            ->method('recordEvent')
            ->with(
                'test_event',
                'Critical issue',
                $contextJson,
                'critical',
                'srv-1',
                'app-1',
                ['email'],
                true,
                $expectedHash,
                30
            )
            ->willReturn(new EventRecordResult(
                eventId: 321,
                alarmId: null,
                serverId: 10,
                appId: 20,
                serverLabel: 'srv-1',
                appLabel: 'app-1',
                serverKey: 'srv-1'
            ))
        ;

        $this->eventRecorder
            ->expects($this->once())
            ->method('upsertAlarmCooldown')
        ;

        $this->alertManager
            ->expects($this->once())
            ->method('handleAlert')
            ->with(
                $this->callback(function (Alert $alert) use ($contextJson) {
                    return $alert->type === 'test_event'
                        && $alert->message === 'Critical issue'
                        && $alert->context === $contextJson
                        && $alert->level->value === 'critical'
                        && $alert->server === 'srv-1'
                        && $alert->app === 'app-1'
                        && $alert->channels === ['email'];
                }),
                $this->isInstanceOf(EventRecordResult::class)
            )
        ;

        $result = $manager->handleEvent(
            type: 'test_event',
            message: 'Critical issue',
            context: $context,
            level: 'critical',
            server: 'srv-1',
            app: 'app-1'
        );

        $this->assertSame(
            ['eventRecorded' => true, 'alarmSent' => true, 'eventId' => 321, 'alarmId' => null],
            $result
        );
    }

    /**
     * Helper to create a fully-initialised EventManager with configurable toggles.
     */
    private function createManager(
        bool $alarmsEnabled = true,
        bool $eventsEnabled = true,
        array $intervalsConfig = []
    ): EventManager {
        return new EventManager(
            $this->eventRecorder,
            $this->alertManager,
            $this->createConfig($alarmsEnabled, $eventsEnabled),
            $this->thresholds,
            $intervalsConfig
        );
    }

    private function createConfig(bool $alarmsEnabled, bool $eventsEnabled): ApplicationConfig
    {
        return new ApplicationConfig(
            environment: new EnvironmentConfig('prod', 'example.com', '/srv/app'),
            general: new GeneralConfig('Test Org', false, false, [], $alarmsEnabled, $eventsEnabled),
            dashboard: new DashboardConfig(3, 1200),
            template: new TemplateConfig(false),
            php: new PhpConfig(0, 'error'),
            logging: new LoggingConfig([], false, [], '/tmp/app.log'),
            database: new DatabaseConfig('localhost', 'user', 'pass', 'db', '3306'),
            mail: new MailConfig(
                dsn: null,
                fromEmail: 'alerts@example.com',
                fromName: 'Alerts',
                alertRecipients: ['ops@example.com'],
                testRecipients: [],
                devRecipients: [],
                mailerApp: 'symfony/mailer',
                mailerDev: 'symfony/mailer-dev',
                smtpDev: new SmtpDevConfig(null, 1025, null, null, 'none', null, null),
                charset: 'UTF-8',
                wordWrap: 78
            ),
            secrets: new SecretsConfig('secret-key')
        );
    }
}
