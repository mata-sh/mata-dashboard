<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use Mata\Config\Dto\ApplicationConfig;
use Mata\Config\Dto\CliSecurityConfig;
use Mata\Config\Dto\DashboardConfig;
use Mata\Config\Dto\DatabaseConfig;
use Mata\Config\Dto\EnvironmentConfig;
use Mata\Config\Dto\GeneralConfig;
use Mata\Config\Dto\LoggingConfig;
use Mata\Config\Dto\MailConfig;
use Mata\Config\Dto\PhpConfig;
use Mata\Config\Dto\RateLimitingConfig;
use Mata\Config\Dto\TemplateConfig;
use Mata\Models\Dto\EventRecordResult;
use Mata\Services\Alarm\AlarmManager;
use Mata\Services\Event\Dto\EventContext;
use Mata\Services\Event\EventAggregatorServiceInterface;
use Mata\Services\Event\EventManager;
use Mata\Services\Event\EventRecorderService;
use Mata\Tests\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * @covers \Mata\Services\Event\EventManager
 *
 * @internal
 */
final class EventManagerTest extends TestCase
{
    private EventRecorderService&MockObject $eventRecorder;
    private EventAggregatorServiceInterface&MockObject $eventAggregator;
    private AlarmManager&MockObject $alarmManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->eventRecorder = $this->createMock(EventRecorderService::class);
        $this->eventAggregator = $this->createMock(EventAggregatorServiceInterface::class);
        $this->alarmManager = $this->createMock(AlarmManager::class);
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

        $this->alarmManager->expects($this->never())->method('handleAlarm');

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
        $this->alarmManager->expects($this->never())->method('handleAlarm');

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

        $this->alarmManager->expects($this->never())->method('handleAlarm');

        $result = $manager->handleEvent(
            type: 'test_event',
            message: 'Test message'
        );

        $this->assertSame(
            ['eventRecorded' => true, 'eventId' => 123],
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
        $manager = new EventManager(
            $this->createConfig($alarmsEnabled, $eventsEnabled),
            $this->alarmManager,
            $this->eventRecorder,
            $this->eventAggregator
        );

        // Set monitoring settings if intervals provided
        if (!empty($intervalsConfig)) {
            $manager->setMonitoringSettings([
                'thresholds' => [],
                'intervals' => $intervalsConfig,
            ]);
        }

        return $manager;
    }

    private function createConfig(bool $alarmsEnabled, bool $eventsEnabled): ApplicationConfig
    {
        return new ApplicationConfig(
            environment: new EnvironmentConfig('prod', 'example.com', '/srv/app', ''),
            general: new GeneralConfig('Test Org', false, false, [], $alarmsEnabled, $eventsEnabled),
            dashboard: new DashboardConfig(3, 1200),
            template: new TemplateConfig(false),
            php: new PhpConfig(0, 'error'),
            logging: new LoggingConfig('/tmp/app.log', 'debug'),
            database: new DatabaseConfig('localhost', 'user', 'pass', 'db', '3306'),
            mail: new MailConfig(
                dsn: null,
                fromEmail: 'alerts@example.com',
                fromName: 'Alarms',
                alarmRecipients: ['ops@example.com'],
                testRecipients: [],
                devRecipients: [],
                mailerApp: 'symfony/mailer',
                mailerDev: 'symfony/mailer-dev',
                charset: 'UTF-8',
                wordWrap: 78
            ),
            cliSecurity: new CliSecurityConfig(false, ['root', 'http']),
            rateLimiting: new RateLimitingConfig(false, 'file', '/tmp/rate_limit_test.json', null, 6379, null, 0, 5, 300, 60, 60, 100, 60)
        );
    }
}
