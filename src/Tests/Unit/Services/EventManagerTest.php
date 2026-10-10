<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use Mata\Config\Dto\AlarmDecisionConfig;
use Mata\Config\Dto\ApplicationConfig;
use Mata\Config\Dto\CliSecurityConfig;
use Mata\Config\Dto\DashboardConfig;
use Mata\Config\Dto\DatabaseConfig;
use Mata\Config\Dto\EnvironmentConfig;
use Mata\Config\Dto\GeneralConfig;
use Mata\Config\Dto\LoggingConfig;
use Mata\Config\Dto\MailConfig;
use Mata\Config\Dto\NodeApiConfig;
use Mata\Config\Dto\OpaAlarmDecisionConfig;
use Mata\Config\Dto\PhpConfig;
use Mata\Config\Dto\RateLimitingConfig;
use Mata\Config\Dto\TemplateConfig;
use Mata\Config\Monitoring\Section\IntervalsConfig;
use Mata\Config\Monitoring\Section\ThresholdsConfig;
use Mata\Services\Alarm\AlarmDirectProcessor;
use Mata\Services\Event\Dto\EventCommand;
use Mata\Services\Event\Dto\EventContext;
use Mata\Services\Event\Dto\EventMetric;
use Mata\Services\Event\Dto\EventRecordResult;
use Mata\Services\Event\EventAggregatorServiceInterface;
use Mata\Services\Event\EventManager;
use Mata\Services\Event\EventRecorderService;
use Mata\Tests\Helpers\MonitoringConfigFactory;
use Mata\Tests\TestCase;

/**
 * @covers \Mata\Services\Event\EventManager
 *
 * @internal
 */
final class EventManagerTest extends TestCase
{
    private EventRecorderService $eventRecorder;
    private EventAggregatorServiceInterface $eventAggregator;
    private AlarmDirectProcessor $alarmDirectProcessor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->eventRecorder = $this->createStub(EventRecorderService::class);
        $this->eventAggregator = $this->createStub(EventAggregatorServiceInterface::class);
        $this->alarmDirectProcessor = $this->createStub(AlarmDirectProcessor::class);
    }

    public function testHandleEventRecordsEventOnly(): void
    {
        $this->useMockEventRecorder();
        $this->alarmDirectProcessor = $this->createMock(AlarmDirectProcessor::class);

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

        $this->alarmDirectProcessor->expects($this->never())->method('process');

        $result = $manager->handle(new EventCommand(
            type: 'test_event',
            message: 'Test message'
        ));

        $this->assertSame(['eventRecorded' => true, 'eventId' => 1], $result);
    }

    public function testShouldCreateEventReturnsFalseWhenEventsDisabled(): void
    {
        $this->useMockEventRecorder();
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

    public function testAppUnreachableUsesItsConfiguredEventCooldown(): void
    {
        $this->useMockEventRecorder();
        $manager = $this->createManager(intervalsConfig: [
            'events' => ['min_event_interval_minutes' => ['app_unreachable' => 15]],
            'alarms' => ['min_alarm_interval_minutes' => []],
        ]);
        $this->eventRecorder->expects($this->once())->method('hasActiveCooldown')->willReturn(true);
        $this->eventRecorder->expects($this->never())->method('recordEvent');

        $result = $manager->handle(new EventCommand('app_unreachable', 'Application unreachable'));

        $this->assertSame(['eventRecorded' => false, 'eventId' => null], $result);
    }

    public function testShouldCreateEventRespectsConfiguredCooldown(): void
    {
        $this->useMockEventRecorder();
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
        $this->useMockEventRecorder();
        $this->alarmDirectProcessor = $this->createMock(AlarmDirectProcessor::class);

        $manager = $this->createManager(eventsEnabled: false, alarmsEnabled: false);

        $this->eventRecorder->expects($this->never())->method('recordEvent');
        $this->alarmDirectProcessor->expects($this->never())->method('process');

        $result = $manager->handle(new EventCommand(
            type: 'test_event',
            message: 'Test message'
        ));

        $this->assertSame(
            ['eventRecorded' => false, 'eventId' => null],
            $result
        );
    }

    public function testHandleEventRecordsEventWithCooldownRespected(): void
    {
        $this->useMockEventRecorder();
        $this->alarmDirectProcessor = $this->createMock(AlarmDirectProcessor::class);

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

        $this->alarmDirectProcessor->expects($this->never())->method('process');

        $result = $manager->handle(new EventCommand(
            type: 'test_event',
            message: 'Test message'
        ));

        $this->assertSame(
            ['eventRecorded' => true, 'eventId' => 123],
            $result
        );
    }

    public function testHandleEventStoresMetricFieldsAndUsesACooldownMetricHash(): void
    {
        $this->useMockEventRecorder();
        $manager = $this->createManager(eventsEnabled: true);
        $stableContext = EventContext::fromArray([
            'metric' => 'disk_space_usage_percent',
            'threshold' => 90,
        ]);
        $expectedHash = hash('sha256', json_encode([
            'type' => 'disk_event',
            'server' => 'srv-1',
            'app' => '',
            'context' => $stableContext->toJson(),
            'channel' => '',
        ], JSON_UNESCAPED_SLASHES));

        $this->eventRecorder
            ->expects($this->exactly(2))
            ->method('hasActiveCooldown')
            ->with($expectedHash)
            ->willReturn(false)
        ;
        $contexts = [];
        $this->eventRecorder
            ->expects($this->exactly(2))
            ->method('recordEvent')
            ->willReturnCallback(function (...$arguments) use (&$contexts): EventRecordResult {
                $contexts[] = $arguments[2];

                return new EventRecordResult(1, null, null, null, null, null, null);
            })
        ;

        $manager->handle(new EventCommand(
            type: 'disk_event',
            message: 'Disk usage',
            server: 'srv-1',
            context: [
                'metric' => 'caller_metric',
                'actual_value' => -1,
                'threshold' => -1,
            ],
            metric: new EventMetric('disk_space_usage_percent', 91, 90, ['total' => 100])
        ));
        $manager->handle(new EventCommand(
            type: 'disk_event',
            message: 'Disk usage',
            server: 'srv-1',
            metric: new EventMetric('disk_space_usage_percent', 92, 90, ['total' => 100])
        ));

        $this->assertSame(
            '{"actual_value":91,"additional_data":{"total":100},"metric":"disk_space_usage_percent","threshold":90}',
            $contexts[0]
        );
        $this->assertSame(
            '{"actual_value":92,"additional_data":{"total":100},"metric":"disk_space_usage_percent","threshold":90}',
            $contexts[1]
        );
    }

    public function testGetServerDataThresholdLimitReturnsConfiguredLimit(): void
    {
        $manager = $this->createManager();
        $manager->setMonitoringConfig(MonitoringConfigFactory::create(
            thresholds: new ThresholdsConfig(
                serverData: ['disk_space_usage_percent' => ['alarm' => 90]],
                appData: [],
                alarmRules: [],
            ),
        ));

        $this->assertSame(90, $manager->getServerDataThresholdLimit('disk_space_usage_percent'));
        $this->assertNull($manager->getServerDataThresholdLimit('missing'));
    }

    private function useMockEventRecorder(): void
    {
        $this->eventRecorder = $this->createMock(EventRecorderService::class);
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
            $this->alarmDirectProcessor,
            $this->eventRecorder,
            $this->eventAggregator
        );

        // Set monitoring configuration if intervals are provided
        if (!empty($intervalsConfig)) {
            $manager->setMonitoringConfig(MonitoringConfigFactory::create(
                intervals: IntervalsConfig::fromArray($intervalsConfig),
            ));
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
                charset: 'UTF-8',
                dashboardUrl: null
            ),
            cliSecurity: new CliSecurityConfig(false, ['root', 'http']),
            rateLimiting: new RateLimitingConfig(false, 'file', '/tmp/rate_limit_test.json', null, 6379, null, 0, 5, 300, 60, 60, 100, 60),
            alarmDecision: new AlarmDecisionConfig(
                'opa',
                100,
                new OpaAlarmDecisionConfig('http://127.0.0.1:3320', '/v1/data/alarms/decision', 5),
            ),
            nodeApi: new NodeApiConfig(NodeApiConfig::DEFAULT_TIMEOUT_SECONDS),
        );
    }
}
