<?php

declare(strict_types=1);

namespace Mata\Services\Monitor\Dto;

use Mata\Config\Monitoring\MonitoringConfig;

/** Immutable monitoring policy resolved from complete monitoring configuration. */
final readonly class AppReachabilityPolicy
{
    public function __construct(
        public ProbePolicy $probe,
        public int $unreachableWindowSeconds,
    ) {}

    public static function fromMonitoringConfig(MonitoringConfig $monitoringConfig): self
    {
        $appUnreachable = is_array($monitoringConfig->thresholds->alarmRules['app_unreachable'] ?? null)
            ? $monitoringConfig->thresholds->alarmRules['app_unreachable']
            : [];
        $windowMinutes = $appUnreachable['window_minutes'] ?? 10;
        if (!is_int($windowMinutes) || $windowMinutes < 1) {
            $windowMinutes = 10;
        }

        return new self(
            probe: ProbePolicy::fromConfig($monitoringConfig->probes),
            unreachableWindowSeconds: $windowMinutes * 60,
        );
    }
}
