<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring;

use Mata\Config\Monitoring\Loader\ChartsConfigLoader;
use Mata\Config\Monitoring\Loader\DataRetentionConfigLoader;
use Mata\Config\Monitoring\Loader\Dto\ConfigLoadProblem;
use Mata\Config\Monitoring\Loader\Dto\ConfigLoadResult;
use Mata\Config\Monitoring\Loader\IntervalsConfigLoader;
use Mata\Config\Monitoring\Loader\MenuConfigLoader;
use Mata\Config\Monitoring\Loader\MonitoringConfigLoadException;
use Mata\Config\Monitoring\Loader\NotificationRulesConfigLoader;
use Mata\Config\Monitoring\Loader\ProbesConfigLoader;
use Mata\Config\Monitoring\Loader\ServersConfigLoader;
use Mata\Config\Monitoring\Loader\ThresholdsConfigLoader;
use Mata\Config\Monitoring\Loader\TtlConfigLoader;
use Mata\Config\Monitoring\Section\AlarmRateLimitsConfig;
use Mata\Config\Monitoring\Section\ChartsConfig;
use Mata\Config\Monitoring\Section\MenuConfig;

/**
 * Loads the fixed set of dashboard monitoring configuration sections.
 *
 * Menu and charts only shape the web UI, so cron omits their loaders and gets empty sections.
 */
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

    /**
     * Alarm rate limits arrive pre-built: AlarmDirectProcessor needs them before the problems
     * found here are recorded, since a broken servers config forces an alarm.
     *
     * @return ConfigLoadResult<MonitoringConfig> with the problems of every section
     *
     * @throws MonitoringConfigLoadException carrying the problems found so far, when a loader without fallback rejects its file
     */
    public function load(string $configRoot, AlarmRateLimitsConfig $alarmRateLimits): ConfigLoadResult
    {
        $problems = [];

        try {
            $config = new MonitoringConfig(
                servers: self::section($this->servers->load($configRoot), $problems),
                ttl: self::section($this->ttl->load($configRoot), $problems),
                dataRetention: self::section($this->dataRetention->load($configRoot), $problems),
                intervals: self::section($this->intervals->load($configRoot), $problems),
                probes: self::section($this->probes->load($configRoot), $problems),
                notificationRules: self::section($this->notificationRules->load($configRoot), $problems),
                thresholds: self::section($this->thresholds->load($configRoot), $problems),
                menu: $this->menu === null ? MenuConfig::empty() : self::section($this->menu->load($configRoot), $problems),
                alarmRateLimits: $alarmRateLimits,
                charts: $this->charts === null ? ChartsConfig::empty() : self::section($this->charts->load($configRoot), $problems),
            );
        } catch (MonitoringConfigLoadException $exception) {
            throw $exception->after($problems);
        }

        return new ConfigLoadResult($config, $problems);
    }

    /**
     * @template T of object
     *
     * @param ConfigLoadResult<T>     $loaded
     * @param list<ConfigLoadProblem> $problems
     *
     * @param-out list<ConfigLoadProblem> $problems
     *
     * @return T
     */
    private static function section(ConfigLoadResult $loaded, array &$problems): object
    {
        $problems = [...$problems, ...$loaded->problems];

        return $loaded->section;
    }
}
