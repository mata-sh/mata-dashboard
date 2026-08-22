<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use GuzzleHttp\Promise\Create;
use Mata\Constants\CacheType;
use Mata\Constants\NodeHealthState;
use Mata\Models\ConfigSyncRepository;
use Mata\Services\Cache\CacheTypeResolver;
use Mata\Services\Collect\ServerDataCollectorInterface;
use Mata\Services\Event\EventManager;
use Mata\Services\Identity\ServerApplicationIdResolver;
use Mata\Services\Monitor\AppErrorMonitorInterface;
use Mata\Services\Monitor\AppReachabilityMonitorInterface;
use Mata\Services\Monitor\Dto\AppFatalErrorPolicy;
use Mata\Services\Monitor\Dto\AppMonitoringSettings;
use Mata\Services\Monitor\Dto\ServerMonitoringServices;
use Mata\Services\Monitor\LogSizeEvaluator;
use Mata\Services\Monitor\NodeHealthMonitorInterface;
use Mata\Services\Monitor\ServerMonitor;
use Mata\Services\Sync\AppInventorySync;
use Mata\Services\View\ServerViewBuilder;
use Mata\Utils\Tools;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * @covers \Mata\Services\View\ServerViewBuilder
 *
 * @internal
 */
final class ServerViewBuilderTest extends TestCase
{
    public function testBuildsServerDataFromOneServiceBundle(): void
    {
        $collector = $this->createMock(ServerDataCollectorInterface::class);
        $collector->expects($this->once())
            ->method('getApps')
            ->with(CacheType::Apps)
            ->willReturn([
                'result' => [],
                'cache_age' => 0,
                'cache_ttl' => 60,
            ]);

        $serverMonitor = $this->createMock(ServerMonitor::class);
        $serverMonitor->expects($this->once())
            ->method('collectServerHealthData')
            ->with($collector, 'server-a', CacheType::ServerData)
            ->willReturn(['cpu' => []]);

        $reachabilityMonitor = $this->createMock(AppReachabilityMonitorInterface::class);
        $reachabilityMonitor->expects($this->never())->method('checkMultipleEndpoints');

        $healthMonitor = $this->createMock(NodeHealthMonitorInterface::class);
        $healthMonitor->expects($this->once())
            ->method('checkAsync')
            ->with('server-a')
            ->willReturn(Create::promiseFor([
                'status' => 'healthy',
                'state' => NodeHealthState::Healthy->value,
            ]));

        $builder = new ServerViewBuilder(
            $serverMonitor,
            $this->logSizeEvaluator(),
            $this->createStub(EventManager::class),
            new Logger('test'),
            new CacheTypeResolver(),
        );

        $result = $builder->buildServersViewData(
            ['server-a' => new ServerMonitoringServices($collector, $reachabilityMonitor, $this->createStub(AppErrorMonitorInterface::class), $healthMonitor)],
            ['server-a' => ['title' => 'Server A']],
            false,
        );

        $this->assertSame('server-a', $result[0]['server']);
        $this->assertSame([], $result[0]['apps']);
        $this->assertSame(NodeHealthState::Healthy->value, $result[0]['health']['state']);
    }

    public function testCompactsSessionCountOnAppCards(): void
    {
        $builder = new ServerViewBuilder(
            $this->createStub(ServerMonitor::class),
            $this->logSizeEvaluator(),
            $this->createStub(EventManager::class),
            new Logger('test'),
            new CacheTypeResolver(),
        );
        $method = new ReflectionMethod($builder, 'formatSessionDataForView');

        $result = $method->invoke($builder, [
            'session' => [
                'type' => 'file',
                'status' => 'ok',
                'count_last_hour' => 236234,
                'count_last_day' => 236234,
                'count_total' => 236234,
                'path' => null,
                'size_kb' => null,
                'error' => null,
            ],
        ]);

        $this->assertSame('236k', $result['formatted_count']);
    }

    public function testBuildsAppLogViewDataWithoutRecordingEvents(): void
    {
        $tools = new class ('/tmp') extends Tools {
            public function formatKilobytes(int $kilobytes): string
            {
                return $kilobytes . ' KB';
            }
        };
        $events = $this->createMock(EventManager::class);
        $events->expects($this->once())
            ->method('getServerDataThresholdLevel')
            ->with(15000, 'log_filesize_kb')
            ->willReturn('alarm');
        $events->expects($this->never())->method('handle');
        $builder = new ServerViewBuilder(
            $this->createStub(ServerMonitor::class),
            new LogSizeEvaluator($tools, $events),
            $events,
            new Logger('test'),
            new CacheTypeResolver(),
        );

        $method = new ReflectionMethod($builder, 'buildAppLogViewData');
        $result = $method->invoke($builder, [
            'logs' => ['/var/log/app/error.log' => 5000, '/var/log/app/debug.log' => 15000],
            'status' => ['fatal' => 3, 'warnings' => 2, 'total' => 200],
        ]);

        $this->assertSame('/var/log/app/debug.log', $result['log_analysis']['max_path']);
        $this->assertSame('15000 KB', $result['log_analysis']['max_size_formatted']);
        $this->assertSame('alarm', $result['log_analysis']['level']);
        $this->assertSame('errors', $result['error_analysis']['show_events_type']);
        $this->assertArrayNotHasKey('alarms_enabled_class', $result['error_analysis']);
        $this->assertTrue($result['error_analysis']['error_monitoring_enabled']);
        $this->assertTrue($result['error_analysis']['loganalysis_enabled']);
        $this->assertTrue($result['error_analysis']['alarms_enabled']);
        $this->assertTrue($result['log_analysis']['logsize_monitoring_enabled']);
    }

    public function testReportsWorkingBadgeWithoutCountsWhenLogAnalysisIsDisabled(): void
    {
        $builder = $this->builder();

        $result = (new ReflectionMethod($builder, 'buildAppLogViewData'))->invoke(
            $builder,
            ['logs' => ['/var/log/app/error.log' => 5000]],
            true,
            new AppMonitoringSettings(loganalysis: false),
        );

        $this->assertSame(0, $result['error_analysis']['show_events_count']);
        $this->assertSame('working', $result['error_analysis']['show_events_type']);
        $this->assertSame('–', $result['error_analysis']['fatal_count']);
        $this->assertSame('–', $result['error_analysis']['warning_count']);
        $this->assertSame('–', $result['error_analysis']['total_count']);
        $this->assertSame(0, $result['error_analysis']['fatal_count_raw']);
        $this->assertSame(0, $result['error_analysis']['warning_count_raw']);
        $this->assertSame(0, $result['error_analysis']['total_count_raw']);
        $this->assertFalse($result['error_analysis']['loganalysis_enabled']);
        $this->assertTrue($result['log_analysis']['logsize_monitoring_enabled']);
    }

    public function testEmitsDisabledLogSizePlaceholderWithoutEvaluating(): void
    {
        $events = $this->createMock(EventManager::class);
        $events->expects($this->never())->method('getServerDataThresholdLevel');
        $builder = new ServerViewBuilder(
            $this->createStub(ServerMonitor::class),
            new LogSizeEvaluator(new Tools('/tmp'), $events),
            $events,
            new Logger('test'),
            new CacheTypeResolver(),
        );

        $result = (new ReflectionMethod($builder, 'buildAppLogViewData'))->invoke(
            $builder,
            ['status' => ['fatal' => 0, 'warnings' => 0, 'total' => 12]],
            true,
            new AppMonitoringSettings(logsize: false),
        );

        $this->assertSame([
            'max_size' => 0,
            'max_size_formatted' => '–',
            'max_path' => null,
            'level' => '',
            'first_log' => '',
            'files' => [],
            'logsize_monitoring_enabled' => false,
        ], $result['log_analysis']);
        $this->assertSame('working', $result['error_analysis']['show_events_type']);
        $this->assertSame('12', $result['error_analysis']['total_count']);
    }

    public function testStatesDisabledAlarmsInErrorAnalysis(): void
    {
        $builder = $this->builder();

        $result = (new ReflectionMethod($builder, 'buildAppLogViewData'))->invoke(
            $builder,
            ['logs' => [], 'status' => ['fatal' => 1, 'warnings' => 0, 'total' => 9]],
            true,
            new AppMonitoringSettings(alarms: false),
        );

        $this->assertFalse($result['error_analysis']['alarms_enabled']);
        $this->assertTrue($result['error_analysis']['error_monitoring_enabled']);
    }

    public function testToleratesAbsentStatusAndLogsKeys(): void
    {
        $builder = $this->builder();

        $result = (new ReflectionMethod($builder, 'buildAppLogViewData'))->invoke(
            $builder,
            ['title' => 'Shop'],
            true,
            new AppMonitoringSettings(loganalysis: false, logsize: false),
        );

        $this->assertSame('–', $result['log_analysis']['max_size_formatted']);
        $this->assertSame('', $result['error_analysis']['first_log']);
        $this->assertSame('working', $result['error_analysis']['show_events_type']);
    }

    public function testReportsDisabledSessionMonitoring(): void
    {
        $builder = $this->builder();

        $result = (new ReflectionMethod($builder, 'formatSessionDataForView'))->invoke(
            $builder,
            ['title' => 'Shop'],
            new AppMonitoringSettings(sessions: false),
        );

        $this->assertSame([
            'level' => '',
            'formatted_count' => '–',
            'tooltip' => 'Session monitoring disabled',
        ], $result);
    }

    public function testFallsBackToUnconfiguredSessionsWhenTheKeyIsAbsent(): void
    {
        $builder = $this->builder();

        $result = (new ReflectionMethod($builder, 'formatSessionDataForView'))->invoke($builder, ['title' => 'Shop']);

        $this->assertSame('–', $result['formatted_count']);
        $this->assertSame('Sessions not configured', $result['tooltip']);
    }

    /**
     * The effective alarm state is the server setting AND the app setting, and it
     * never changes the observed counts reported by the node.
     */
    #[DataProvider('errorAlarmStateProvider')]
    public function testResolvesEffectiveErrorAlarmState(
        ?bool $serverSetting,
        bool $appSetting,
        bool $expected
    ): void {
        $builder = new ServerViewBuilder(
            $this->createStub(ServerMonitor::class),
            $this->logSizeEvaluator(),
            $this->createStub(EventManager::class),
            new Logger('test'),
            new CacheTypeResolver(),
        );
        $serverConfig = $serverSetting === null ? [] : ['error_monitoring_enabled' => $serverSetting];

        $policy = (new ReflectionMethod($builder, 'buildFatalErrorPolicy'))
            ->invoke($builder, 'web01', $serverConfig);
        $resolved = (new ReflectionMethod($builder, 'resolveErrorAlarmsEnabled'))
            ->invoke($builder, $policy, ['monitoring' => ['loganalysis' => $appSetting]]);

        $this->assertSame($expected, $resolved);
    }

    /** @return array<string,array{null|bool,bool,bool}> */
    public static function errorAlarmStateProvider(): array
    {
        return [
            'omitted server setting defaults to enabled' => [null, true, true],
            'both enabled' => [true, true, true],
            'app disabled' => [true, false, false],
            'server disabled' => [false, true, false],
            'both disabled' => [false, false, false],
        ];
    }

    public function testFallsBackToEnabledOnUnusableConfiguration(): void
    {
        $builder = new ServerViewBuilder(
            $this->createStub(ServerMonitor::class),
            $this->logSizeEvaluator(),
            $this->createStub(EventManager::class),
            new Logger('test'),
            new CacheTypeResolver(),
        );

        $policy = (new ReflectionMethod($builder, 'buildFatalErrorPolicy'))
            ->invoke($builder, 'web01', ['error_monitoring_enabled' => 'false']);
        $this->assertNull($policy);

        $method = new ReflectionMethod($builder, 'resolveErrorAlarmsEnabled');
        $this->assertTrue($method->invoke($builder, null, []));
        $this->assertTrue(
            $method->invoke($builder, AppFatalErrorPolicy::fromServerConfig([]), ['monitoring' => ['loganalysis' => 'no']])
        );
    }

    /**
     * The reachability decision lives in EndpointTarget::fromRawAppData(), so the
     * view builder hands over the raw payload without pre-filtering it.
     */
    public function testDelegatesTargetSelectionToEndpointTarget(): void
    {
        $builder = $this->builder();
        $reachabilityMonitor = $this->createMock(AppReachabilityMonitorInterface::class);
        $reachabilityMonitor->expects($this->once())
            ->method('checkMultipleEndpoints')
            ->willReturnCallback(function (array $targets): array {
                $this->assertSame(['shop', 'blog'], array_keys($targets));
                $this->assertSame('https://shop.example.test', $targets['shop']->url);

                return ['shop' => ['reachable' => true]];
            });

        $method = new ReflectionMethod($builder, 'checkAppsAvailability');
        $result = $method->invoke($builder, [
            'shop' => ['url' => 'https://shop.example.test'],
            'blog' => ['url' => 'https://blog.example.test'],
        ], $reachabilityMonitor, 'server-a');

        $this->assertSame(['shop' => ['reachable' => true]], $result);
    }

    public function testSyncsDiscoveredApplicationsOnForceRefresh(): void
    {
        $appPayload = $this->appPayload();

        $collector = $this->createMock(ServerDataCollectorInterface::class);
        $collector->expects($this->once())
            ->method('getApps')
            ->willReturn(['result' => ['shop' => $appPayload], 'cache_age' => 0, 'cache_ttl' => 60]);

        $serverMonitor = $this->createMock(ServerMonitor::class);
        $serverMonitor->expects($this->once())
            ->method('collectServerHealthData')
            ->willReturn(['cpu' => []]);

        $repository = $this->createMock(ConfigSyncRepository::class);
        $repository->expects($this->once())
            ->method('upsertApplication')
            ->with('shop', 'Shop', 'prod')
            ->willReturn(true);
        $sync = new AppInventorySync($repository, new ServerApplicationIdResolver(), new Logger('test'));

        $builder = new ServerViewBuilder(
            $serverMonitor,
            $this->logSizeEvaluator(),
            $this->createStub(EventManager::class),
            new Logger('test'),
            new CacheTypeResolver(),
            $sync,
        );

        $result = $builder->buildServerViewData(
            'server-a',
            [],
            $collector,
            $this->createStub(AppReachabilityMonitorInterface::class),
            true,
        );

        $this->assertSame('shop', $result['apps'][0]['key']);
    }

    public function testSkipsApplicationSyncWithoutForceRefresh(): void
    {
        $repository = $this->createMock(ConfigSyncRepository::class);
        $repository->expects($this->never())->method('upsertApplication');
        $sync = new AppInventorySync($repository, new ServerApplicationIdResolver(), new Logger('test'));

        $collector = $this->createStub(ServerDataCollectorInterface::class);
        $collector->method('getApps')->willReturn([
            'result' => ['shop' => $this->appPayload()],
            'cache_age' => 0,
            'cache_ttl' => 60,
        ]);

        $builder = new ServerViewBuilder(
            $this->createStub(ServerMonitor::class),
            $this->logSizeEvaluator(),
            $this->createStub(EventManager::class),
            new Logger('test'),
            new CacheTypeResolver(),
            $sync,
        );

        $result = $builder->buildServerViewData(
            'server-a',
            [],
            $collector,
            $this->createStub(AppReachabilityMonitorInterface::class),
            false,
        );

        $this->assertSame('shop', $result['apps'][0]['key']);
    }

    public function testMissingBundleBuildsAnErrorCard(): void
    {
        $builder = new ServerViewBuilder(
            $this->createStub(ServerMonitor::class),
            $this->logSizeEvaluator(),
            $this->createStub(EventManager::class),
            new Logger('test'),
            new CacheTypeResolver(),
        );

        $result = $builder->buildServersViewData([], ['server-a' => []], false);

        $this->assertSame('Monitoring services unavailable for server server-a', $result[0]['error']);
        $this->assertSame(NodeHealthState::Unknown->value, $result[0]['health']['state']);
    }

    private function builder(): ServerViewBuilder
    {
        return new ServerViewBuilder(
            $this->createStub(ServerMonitor::class),
            $this->logSizeEvaluator(),
            $this->createStub(EventManager::class),
            new Logger('test'),
            new CacheTypeResolver(),
        );
    }

    private function logSizeEvaluator(): LogSizeEvaluator
    {
        $events = $this->createStub(EventManager::class);
        $events->method('getServerDataThresholdLevel')->willReturn('good');

        return new LogSizeEvaluator(new Tools('/tmp'), $events);
    }

    /** @return array<string,mixed> */
    private function appPayload(): array
    {
        return [
            'title' => 'Shop',
            'env' => 'prod',
            'url' => 'https://shop.example.test',
            'monitoring' => ['reachability' => false],
            'logs' => [],
            'status' => ['fatal' => 0, 'warnings' => 0, 'total' => 0],
            'session' => [
                'type' => 'file',
                'status' => 'unconfigured',
                'count_last_hour' => null,
                'count_last_day' => null,
                'count_total' => null,
                'path' => null,
                'size_kb' => null,
                'error' => null,
            ],
        ];
    }
}
