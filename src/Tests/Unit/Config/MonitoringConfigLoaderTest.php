<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Config;

use Mata\Config\Monitoring\Loader\ChartsConfigLoader;
use Mata\Config\Monitoring\Loader\DataRetentionConfigLoader;
use Mata\Config\Monitoring\Loader\IntervalsConfigLoader;
use Mata\Config\Monitoring\Loader\MenuConfigLoader;
use Mata\Config\Monitoring\Loader\MonitoringConfigLoadException;
use Mata\Config\Monitoring\Loader\NotificationRulesConfigLoader;
use Mata\Config\Monitoring\Loader\ProbesConfigLoader;
use Mata\Config\Monitoring\Loader\ServersConfigLoader;
use Mata\Config\Monitoring\Loader\ThresholdsConfigLoader;
use Mata\Config\Monitoring\Loader\TtlConfigLoader;
use Mata\Config\Monitoring\MonitoringConfig;
use Mata\Config\Monitoring\MonitoringConfigLoader;
use Mata\Config\Monitoring\Section\AlarmRateLimit;
use Mata\Config\Monitoring\Section\AlarmRateLimitsConfig;
use Mata\Models\Dto\EventRecordResult;
use Mata\Services\Event\EventRecorderService;
use Mata\Services\ServerMetrics\MetricCatalog;
use Mata\Services\ServerMetrics\MetricQueryPolicy;
use Mata\Tests\TestCase;

/**
 * @covers \Mata\Config\Monitoring\MonitoringConfigLoader
 *
 * @internal
 */
final class MonitoringConfigLoaderTest extends TestCase
{
    private string $configRoot;
    private EventRecorderService $eventRecorder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configRoot = sys_get_temp_dir() . '/mata-monitoring-' . bin2hex(random_bytes(6));
        mkdir($this->configRoot);
        $this->eventRecorder = $this->createStub(EventRecorderService::class);
        $this->eventRecorder->method('recordEvent')->willReturn(new EventRecordResult(1, null, null, null, null, null));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->configRoot . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->configRoot);
        parent::tearDown();
    }

    public function testBuildsAggregateFromNamedTypedSections(): void
    {
        $files = [
            'servers.json' => ['server' => ['disabled' => true]],
            'ttl.json' => ['apps' => 42],
            'data_retention.json' => ['events' => ['retention_days' => 10]],
            'intervals.json' => ['events' => ['min_event_interval_minutes' => ['_default' => 5]]],
            'probes.json' => ['max_retries' => 3],
            'notification_rules.json' => (object) [],
            'thresholds.json' => ['alarms' => ['app_unreachable' => ['strategy' => 'immediate']]],
            'menu.json' => ['metrics' => false],
            'charts.json' => ['dashboard' => ['default_metric' => 'disk_used']],
        ];
        foreach ($files as $name => $payload) {
            file_put_contents($this->configRoot . '/' . $name, json_encode($payload, JSON_THROW_ON_ERROR));
        }

        $limits = new AlarmRateLimitsConfig(new AlarmRateLimit(1, 2, 3), [], []);
        $config = $this->loader(serversFallback: false)->load($this->configRoot, $limits);

        $this->assertInstanceOf(MonitoringConfig::class, $config);
        $this->assertSame([], $config->servers->values);
        $this->assertSame(42, $config->ttl->get('apps'));
        $this->assertSame(10, $config->dataRetention->events['retention_days']);
        $this->assertSame(5, $config->intervals->eventMinimumMinutes['_default']);
        $this->assertSame(3, $config->probes->maxRetries);
        $this->assertSame(['strategy' => 'immediate'], $config->thresholds->alarmRules['app_unreachable']);
        $this->assertSame(['metrics' => false], $config->menu->overrides);
        $this->assertSame('disk_used', $config->charts->dashboard?->defaultMetric);
        $this->assertSame([
            'cpu',
            'ram_percent',
            'ram_used',
            'disk_percent',
            'disk_used',
            'file_count',
            'process_count',
        ], array_keys($config->charts->dashboard?->metrics ?? []));
        $this->assertSame($limits, $config->alarmRateLimits);
    }

    public function testWebStyleServerLoaderFallsBackButCronStyleThrows(): void
    {
        $web = new ServersConfigLoader($this->eventManagerFor($this->eventRecorder), fallbackOnFailure: true);
        $this->assertSame([], $web->load($this->configRoot)->values);

        $cron = new ServersConfigLoader($this->eventManagerFor($this->eventRecorder), fallbackOnFailure: false);
        $this->expectException(MonitoringConfigLoadException::class);
        $cron->load($this->configRoot);
    }

    public function testAggregateWithoutMenuLoaderDoesNotReadMenuConfig(): void
    {
        $files = [
            'servers.json' => ['server' => ['disabled' => true]],
            'ttl.json' => ['apps' => 42],
            'data_retention.json' => ['events' => ['retention_days' => 10]],
            'intervals.json' => ['events' => ['min_event_interval_minutes' => ['_default' => 5]]],
            'probes.json' => ['max_retries' => 3],
            'notification_rules.json' => (object) [],
            'thresholds.json' => ['server_data' => []],
        ];
        foreach ($files as $name => $payload) {
            file_put_contents($this->configRoot . '/' . $name, json_encode($payload, JSON_THROW_ON_ERROR));
        }
        file_put_contents($this->configRoot . '/menu.json', '{');

        $eventRecorder = $this->createMock(EventRecorderService::class);
        $eventRecorder->expects($this->never())->method('recordEvent');
        $config = $this->loader(
            serversFallback: false,
            eventRecorder: $eventRecorder,
            loadMenu: false,
        )->load($this->configRoot, AlarmRateLimitsConfig::default());

        $this->assertSame([], $config->menu->overrides);
    }

    private function loader(
        bool $serversFallback,
        ?EventRecorderService $eventRecorder = null,
        bool $loadMenu = true,
    ): MonitoringConfigLoader {
        $eventRecorder ??= $this->eventRecorder;

        return new MonitoringConfigLoader(
            servers: new ServersConfigLoader($this->eventManagerFor($eventRecorder), fallbackOnFailure: $serversFallback),
            ttl: new TtlConfigLoader($this->eventManagerFor($eventRecorder)),
            dataRetention: new DataRetentionConfigLoader($this->eventManagerFor($eventRecorder)),
            intervals: new IntervalsConfigLoader($this->eventManagerFor($eventRecorder)),
            probes: new ProbesConfigLoader($this->eventManagerFor($eventRecorder)),
            notificationRules: new NotificationRulesConfigLoader($this->eventManagerFor($eventRecorder)),
            thresholds: new ThresholdsConfigLoader($this->eventManagerFor($eventRecorder)),
            menu: $loadMenu ? new MenuConfigLoader($this->eventManagerFor($eventRecorder), ['metrics']) : null,
            charts: new ChartsConfigLoader(
                events: $this->eventManagerFor($eventRecorder),
                metricCatalog: new MetricCatalog(),
                queryPolicy: new MetricQueryPolicy(),
            ),
        );
    }
}
