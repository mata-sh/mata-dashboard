<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Services;

use PHPUnit\Framework\MockObject\MockObject;
use Watcher\Config\Dto\ApplicationConfig;
use Watcher\Config\Dto\CliSecurityConfig;
use Watcher\Config\Dto\DashboardConfig;
use Watcher\Config\Dto\DatabaseConfig;
use Watcher\Config\Dto\EnvironmentConfig;
use Watcher\Config\Dto\GeneralConfig;
use Watcher\Config\Dto\LoggingConfig;
use Watcher\Config\Dto\MailConfig;
use Watcher\Config\Dto\PhpConfig;
use Watcher\Config\Dto\RateLimitingConfig;
use Watcher\Config\Dto\SecretsConfig;
use Watcher\Config\Dto\SmtpDevConfig;
use Watcher\Config\Dto\TemplateConfig;
use Watcher\Models\Dto\EventRecordResult;
use Watcher\Services\Foundation\AlertManager;
use Watcher\Services\Foundation\Dto\EventContext;
use Watcher\Services\Foundation\EventAggregatorService;
use Watcher\Services\Foundation\EventManager;
use Watcher\Services\Foundation\EventRecorderService;
use Watcher\Tests\TestCase;

/**
 * @covers \Watcher\Services\Foundation\EventManager
 *
 * @internal
 */
final class EventManagerTest extends TestCase
{
    private EventRecorderService&MockObject $eventRecorder;
    private EventAggregatorService&MockObject $eventAggregator;
    private AlertManager&MockObject $alertManager;

    /** @var array<string,mixed> */
    private array $thresholds;

    protected function setUp(): void
    {
        parent::setUp();

        $this->eventRecorder = $this->createMock(EventRecorderService::class);
        $this->eventAggregator = $this->createMock(EventAggregatorService::class);
        $this->alertManager = $this->createMock(AlertManager::class);
        $this->thresholds = [
            'alarms' => [
                'test_event' => [
                    'alarm_threshold_event_count' => 1,
                ],
            ],
        ];
    }

    public function testHandleEventRecordsEventOnly(): void
    {
        $manager = $this->createManager(alarmsEnabled: false, eventsEnabled: true);

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

        $this->assertSame(['eventRecorded' => true, 'eventId' => 1], $result);
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

    public function testHandleEventSkipsWhenEventsDisabled(): void
    {
        $manager = $this->createManager(eventsEnabled: false, alarmsEnabled: false);

        $this->eventRecorder->expects($this->never())->method('recordEvent');
        $this->alertManager->expects($this->never())->method('handleAlert');

        $result = $manager->handleEvent(
            type: 'test_event',
            message: 'Test message'
        );

        $this->assertSame(
            ['eventRecorded' => false, 'eventId' => null],
            $result
        );
    }

    public function testHandleEventRecordsEventWithCooldownRespected(): void
    {
        $intervalsConfig = [
            'events' => ['min_event_interval_minutes' => ['_default' => 30]],
            'alarms' => ['min_alarm_interval_minutes' => []],
        ];
        $manager = $this->createManager(eventsEnabled: true, alarmsEnabled: false, intervalsConfig: $intervalsConfig);

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
            ['eventRecorded' => true, 'eventId' => 123],
            $result
        );
    }

    public function testShouldTriggerAlarmForEventReturnsTrueWhenThresholdMet(): void
    {
        $manager = $this->createManager(alarmsEnabled: true);

        $this->eventAggregator
            ->expects($this->once())
            ->method('getLastEventsByContext')
            ->with('test_event', null, 'srv-1', 'app-1', 720)
            ->willReturn([['id' => 1]])  // Return at least one event to meet threshold of 1
        ;

        $result = $manager->shouldTriggerAlarmForEvent('test_event', 'srv-1', 'app-1');

        $this->assertTrue($result);
    }

    public function testShouldTriggerAlarmForEventReturnsFalseWhenThresholdNotMet(): void
    {
        $manager = $this->createManager(alarmsEnabled: true);

        $this->eventAggregator
            ->expects($this->once())
            ->method('getLastEventsByContext')
            ->willReturn([])  // No events, threshold not met
        ;

        $result = $manager->shouldTriggerAlarmForEvent('test_event', 'srv-1', 'app-1');

        $this->assertFalse($result);
    }

    public function testShouldTriggerAlarmForEventReturnsFalseWhenAlarmsDisabled(): void
    {
        $manager = $this->createManager(alarmsEnabled: false);

        $this->eventAggregator->expects($this->never())->method('getLastEventsByContext');

        $result = $manager->shouldTriggerAlarmForEvent('test_event', 'srv-1', 'app-1');

        $this->assertFalse($result);
    }

    public function testFilterReadyChannelsReturnsAllWhenNoCooldowns(): void
    {
        $manager = $this->createManager();

        $this->eventRecorder
            ->expects($this->once())
            ->method('getActiveAlarmCooldowns')
            ->willReturn([])  // No active cooldowns
        ;

        $result = $manager->filterReadyChannels(
            type: 'test_event',
            requestedChannels: ['email', 'sms'],
            server: 'srv-1',
            app: 'app-1',
            context: EventContext::empty()
        );

        $this->assertSame(['email', 'sms'], $result);
    }

    public function testFilterReadyChannelsFiltersOutCooledDownChannels(): void
    {
        $manager = $this->createManager();

        $context = EventContext::empty();
        $emailHash = hash('sha256', json_encode([
            'type' => 'test_event',
            'server' => 'srv-1',
            'app' => 'app-1',
            'context' => $context->toJson(),
            'channel' => 'email',
        ], JSON_UNESCAPED_SLASHES));

        $this->eventRecorder
            ->expects($this->once())
            ->method('getActiveAlarmCooldowns')
            ->willReturn([$emailHash])  // Email channel on cooldown
        ;

        $result = $manager->filterReadyChannels(
            type: 'test_event',
            requestedChannels: ['email', 'sms'],
            server: 'srv-1',
            app: 'app-1',
            context: $context
        );

        $this->assertSame(['sms'], $result);
    }

    /**
     * Helper to create a fully-initialised EventManager with configurable toggles.
     */
    private function createManager(
        bool $alarmsEnabled = true,
        bool $eventsEnabled = true,
        array $intervalsConfig = []
    ): EventManager {
        $manager = new EventManager(
            $this->createConfig($alarmsEnabled, $eventsEnabled),
            $this->alertManager,
            $this->eventRecorder,
            $this->eventAggregator
        );

        // Set monitoring settings if thresholds or intervals provided
        if (!empty($this->thresholds) || !empty($intervalsConfig)) {
            $manager->setMonitoringSettings([
                'thresholds' => $this->thresholds,
                'intervals' => $intervalsConfig,
            ]);
        }

        return $manager;
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
            secrets: new SecretsConfig('secret-key'),
            cliSecurity: new CliSecurityConfig(false, ['root', 'http']),
            rateLimiting: new RateLimitingConfig(false, 'file', '/tmp/rate_limit_test.json', null, 6379, null, 0, 5, 300, 60, 60, 100, 60)
        );
    }
}
