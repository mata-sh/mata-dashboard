<?php

declare(strict_types=1);

namespace Mata\Services\Monitor\Dto;

/** Immutable monitoring policy resolved from complete monitoring settings. */
final readonly class AppReachabilityPolicy
{
    public function __construct(
        public ProbePolicy $probe,
        public int $unreachableWindowSeconds,
    ) {}

    /** @param array<string,mixed> $monitoringSettings */
    public static function fromSettings(array $monitoringSettings): self
    {
        $probes = $monitoringSettings['probes'] ?? [];
        $thresholds = $monitoringSettings['thresholds'] ?? [];
        $appUnreachable = is_array($thresholds)
            && is_array($thresholds['alarms'] ?? null)
            && is_array($thresholds['alarms']['app_unreachable'] ?? null)
            ? $thresholds['alarms']['app_unreachable']
            : [];
        $windowMinutes = is_numeric($appUnreachable['window_minutes'] ?? null)
            ? (int) $appUnreachable['window_minutes']
            : 10;

        return new self(
            probe: ProbePolicy::fromArray(is_array($probes) ? $probes : []),
            unreachableWindowSeconds: max(60, $windowMinutes * 60),
        );
    }
}
