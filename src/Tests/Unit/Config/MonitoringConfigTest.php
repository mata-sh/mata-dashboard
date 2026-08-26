<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Config;

use Mata\Config\Monitoring\MonitoringConfig;
use Mata\Config\Monitoring\Section\AlarmRateLimit;
use Mata\Config\Monitoring\Section\AlarmRateLimitsConfig;
use Mata\Config\Monitoring\Section\ChartsConfig;
use Mata\Config\Monitoring\Section\DataRetentionConfig;
use Mata\Config\Monitoring\Section\IntervalsConfig;
use Mata\Config\Monitoring\Section\MenuConfig;
use Mata\Config\Monitoring\Section\NotificationRulesConfig;
use Mata\Config\Monitoring\Section\ProbesConfig;
use Mata\Config\Monitoring\Section\ServersConfig;
use Mata\Config\Monitoring\Section\ThresholdsConfig;
use Mata\Config\Monitoring\Section\TtlConfig;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @covers \Mata\Config\Monitoring\MonitoringConfig
 *
 * @internal
 */
final class MonitoringConfigTest extends TestCase
{
    public function testSectionConstructorsRequireExplicitValues(): void
    {
        foreach ([
            AlarmRateLimit::class,
            AlarmRateLimitsConfig::class,
            ChartsConfig::class,
            DataRetentionConfig::class,
            IntervalsConfig::class,
            MenuConfig::class,
            NotificationRulesConfig::class,
            ProbesConfig::class,
            ServersConfig::class,
            ThresholdsConfig::class,
            TtlConfig::class,
        ] as $class) {
            $constructor = (new ReflectionClass($class))->getConstructor();

            $this->assertNotNull($constructor);
            $this->assertSame($constructor->getNumberOfParameters(), $constructor->getNumberOfRequiredParameters(), $class);
        }
    }

    public function testConstructsFromNamedTypedSections(): void
    {
        $config = new MonitoringConfig(
            servers: ServersConfig::fromArray(['server' => [
                'title' => 'Server',
                'api_url' => 'https://server.example.test',
                'api_key' => 'key',
            ]]),
            ttl: TtlConfig::fromArray(['apps' => 60]),
            dataRetention: DataRetentionConfig::fromArray(['metrics' => ['raw_retention_days' => 2]]),
            intervals: IntervalsConfig::fromArray([
                'events' => ['min_event_interval_minutes' => ['event' => 4]],
                'alarms' => ['min_alarm_interval_minutes' => ['alarm' => 8]],
            ]),
            probes: new ProbesConfig(5, 1, 500, 1500),
            notificationRules: new NotificationRulesConfig(
                defaults: ['enabled' => false, 'channels' => ['email']],
                eventTypes: [],
                applicationOverrides: [],
            ),
            thresholds: ThresholdsConfig::fromArray(
                ['alarms' => ['event' => ['strategy' => 'immediate']]],
                static function (string $message): void {},
            ),
            menu: MenuConfig::fromArray(
                ['metrics' => false],
                ['metrics'],
                static function (string $message): void {},
            ),
            alarmRateLimits: new AlarmRateLimitsConfig(new AlarmRateLimit(1, 2, 3), [], []),
            charts: ChartsConfig::empty(),
        );

        $this->assertSame(['server'], array_keys($config->servers->values));
        $this->assertSame(60, $config->ttl->get('apps'));
        $this->assertSame(2, $config->dataRetention->metrics['raw_retention_days']);
        $this->assertSame(4, $config->intervals->eventMinimumMinutes['event']);
        $this->assertFalse($config->notificationRules->defaults['enabled']);
        $this->assertSame(['metrics' => false], $config->menu->overrides);
        $this->assertSame(1, $config->alarmRateLimits->getGlobalDefault()->getPerMinute());
        $this->assertFalse(property_exists($config, 'environment'));
        $this->assertEquals(ChartsConfig::empty(), $config->charts);
    }
}
