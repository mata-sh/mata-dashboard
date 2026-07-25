<?php

declare(strict_types=1);

namespace Mata\Services\Monitor\Dto;

/** Dashboard monitoring settings and prebuilt per-server services. */
final readonly class DashboardMonitoringContext
{
    /**
     * @param array<string,mixed>                    $serversConfig
     * @param array<string,mixed>                    $ttlConfig
     * @param array<string,mixed>                    $thresholdsConfig
     * @param array<string,ServerMonitoringServices> $servicesByServer
     * @param array<string,mixed>                    $intervalsConfig
     * @param array<string,mixed>                    $probesConfig
     */
    public function __construct(
        public array $serversConfig,
        public array $ttlConfig,
        public array $thresholdsConfig,
        public string $monitoringEnvironment,
        public array $servicesByServer,
        public array $intervalsConfig = [],
        public array $probesConfig = [],
    ) {}

    /**
     * @param array<string,mixed>                    $monitoringSettings
     * @param array<string,ServerMonitoringServices> $servicesByServer
     */
    public static function fromSettingsAndServices(array $monitoringSettings, array $servicesByServer): self
    {
        return new self(
            serversConfig: is_array($monitoringSettings['servers'] ?? null) ? $monitoringSettings['servers'] : [],
            ttlConfig: is_array($monitoringSettings['ttl'] ?? null) ? $monitoringSettings['ttl'] : [],
            thresholdsConfig: is_array($monitoringSettings['thresholds'] ?? null) ? $monitoringSettings['thresholds'] : [],
            monitoringEnvironment: is_string($monitoringSettings['environment'] ?? null) ? $monitoringSettings['environment'] : 'unset',
            servicesByServer: $servicesByServer,
            intervalsConfig: is_array($monitoringSettings['intervals'] ?? null) ? $monitoringSettings['intervals'] : [],
            probesConfig: is_array($monitoringSettings['probes'] ?? null) ? $monitoringSettings['probes'] : [],
        );
    }
}
