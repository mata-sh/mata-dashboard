<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring;

use Mata\Config\Monitoring\Loader\ChartsConfigLoader;
use Mata\Config\Monitoring\Loader\DataRetentionConfigLoader;
use Mata\Config\Monitoring\Loader\IntervalsConfigLoader;
use Mata\Config\Monitoring\Loader\MenuConfigLoader;
use Mata\Config\Monitoring\Loader\NotificationRulesConfigLoader;
use Mata\Config\Monitoring\Loader\ProbesConfigLoader;
use Mata\Config\Monitoring\Loader\ServersConfigLoader;
use Mata\Config\Monitoring\Loader\ThresholdsConfigLoader;
use Mata\Config\Monitoring\Loader\TtlConfigLoader;
use Mata\Config\Monitoring\Section\AlarmRateLimitsConfig;
use Mata\Config\Monitoring\Section\ChartsConfig;
use Mata\Config\Monitoring\Section\MenuConfig;

/** Loads the fixed set of dashboard monitoring configuration sections. */
final readonly class MonitoringConfigLoader
{
    public function __construct(
        private ServersConfigLoader $servers,
        private TtlConfigLoader $ttl,
        private DataRetentionConfigLoader $dataRetention,
        private IntervalsConfigLoader $intervals,
        private ProbesConfigLoader $probes,
        private NotificationRulesConfigLoader $notificationRules,
        private ThresholdsConfigLoader $thresholds,
        private ?MenuConfigLoader $menu = null,
        private ?ChartsConfigLoader $charts = null,
    ) {}

    public function load(string $configRoot, AlarmRateLimitsConfig $alarmRateLimits): MonitoringConfig
    {
        return new MonitoringConfig(
            servers: $this->servers->load($configRoot),
            ttl: $this->ttl->load($configRoot),
            dataRetention: $this->dataRetention->load($configRoot),
            intervals: $this->intervals->load($configRoot),
            probes: $this->probes->load($configRoot),
            notificationRules: $this->notificationRules->load($configRoot),
            thresholds: $this->thresholds->load($configRoot),
            menu: $this->menu?->load($configRoot) ?? MenuConfig::empty(),
            alarmRateLimits: $alarmRateLimits,
            charts: $this->charts?->load($configRoot) ?? ChartsConfig::empty(),
        );
    }
}
