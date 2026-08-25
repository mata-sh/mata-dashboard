<?php

declare(strict_types=1);

namespace Mata\Tests\Helpers;

use Mata\Config\Monitoring\MonitoringConfig;
use Mata\Config\Monitoring\Section\AlarmRateLimitsConfig;
use Mata\Config\Monitoring\Section\DataRetentionConfig;
use Mata\Config\Monitoring\Section\IntervalsConfig;
use Mata\Config\Monitoring\Section\MenuConfig;
use Mata\Config\Monitoring\Section\NotificationRulesConfig;
use Mata\Config\Monitoring\Section\ProbesConfig;
use Mata\Config\Monitoring\Section\ServersConfig;
use Mata\Config\Monitoring\Section\ThresholdsConfig;
use Mata\Config\Monitoring\Section\TtlConfig;

final class MonitoringConfigFactory
{
    public static function create(
        ?ServersConfig $servers = null,
        ?TtlConfig $ttl = null,
        ?DataRetentionConfig $dataRetention = null,
        ?IntervalsConfig $intervals = null,
        ?ProbesConfig $probes = null,
        ?NotificationRulesConfig $notificationRules = null,
        ?ThresholdsConfig $thresholds = null,
        ?MenuConfig $menu = null,
        ?AlarmRateLimitsConfig $alarmRateLimits = null,
    ): MonitoringConfig {
        return new MonitoringConfig(
            servers: $servers ?? ServersConfig::empty(),
            ttl: $ttl ?? TtlConfig::empty(),
            dataRetention: $dataRetention ?? DataRetentionConfig::empty(),
            intervals: $intervals ?? IntervalsConfig::empty(),
            probes: $probes ?? ProbesConfig::default(),
            notificationRules: $notificationRules ?? NotificationRulesConfig::default(),
            thresholds: $thresholds ?? ThresholdsConfig::empty(),
            menu: $menu ?? MenuConfig::empty(),
            alarmRateLimits: $alarmRateLimits ?? AlarmRateLimitsConfig::default(),
        );
    }
}
