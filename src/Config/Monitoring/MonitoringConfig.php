<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring;

use Mata\Config\Monitoring\Section\AlarmRateLimitsConfig;
use Mata\Config\Monitoring\Section\DataRetentionConfig;
use Mata\Config\Monitoring\Section\IntervalsConfig;
use Mata\Config\Monitoring\Section\MenuConfig;
use Mata\Config\Monitoring\Section\NotificationRulesConfig;
use Mata\Config\Monitoring\Section\ProbesConfig;
use Mata\Config\Monitoring\Section\ServersConfig;
use Mata\Config\Monitoring\Section\ThresholdsConfig;
use Mata\Config\Monitoring\Section\TtlConfig;

/** Complete dashboard-owned monitoring configuration. */
final readonly class MonitoringConfig
{
    public function __construct(
        public ServersConfig $servers,
        public TtlConfig $ttl,
        public DataRetentionConfig $dataRetention,
        public IntervalsConfig $intervals,
        public ProbesConfig $probes,
        public NotificationRulesConfig $notificationRules,
        public ThresholdsConfig $thresholds,
        public MenuConfig $menu,
        public AlarmRateLimitsConfig $alarmRateLimits,
    ) {}
}
