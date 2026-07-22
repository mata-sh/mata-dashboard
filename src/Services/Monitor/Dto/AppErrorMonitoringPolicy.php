<?php

declare(strict_types=1);

namespace Mata\Services\Monitor\Dto;

use InvalidArgumentException;

/** Resolves the independent fatal-error and log-size monitoring switches. */
final readonly class AppErrorMonitoringPolicy
{
    public function __construct(
        private bool $serverErrorMonitoringEnabled,
        private bool $serverLogsizeMonitoringEnabled,
    ) {}

    /** @param array<string,mixed> $serverConfig */
    public static function fromServerConfig(array $serverConfig): self
    {
        return new self(
            self::serverSetting($serverConfig, 'error_monitoring_enabled'),
            self::serverSetting($serverConfig, 'logsize_monitoring_enabled'),
        );
    }

    /** @param array<string,mixed> $appData */
    public function errorMonitoringEnabled(array $appData): bool
    {
        return $this->serverErrorMonitoringEnabled && self::appSetting($appData, 'error_monitoring_enabled');
    }

    /** @param array<string,mixed> $appData */
    public function logsizeMonitoringEnabled(array $appData): bool
    {
        return $this->serverLogsizeMonitoringEnabled && self::appSetting($appData, 'logsize_monitoring_enabled');
    }

    /** @param array<string,mixed> $config */
    private static function serverSetting(array $config, string $setting): bool
    {
        if (!array_key_exists($setting, $config)) {
            return true;
        }
        if (!is_bool($config[$setting])) {
            throw new InvalidArgumentException("{$setting} must be a JSON boolean when configured");
        }

        return $config[$setting];
    }

    /** @param array<string,mixed> $appData */
    private static function appSetting(array $appData, string $setting): bool
    {
        if (!array_key_exists($setting, $appData) || !is_bool($appData[$setting])) {
            throw new InvalidArgumentException("Node application {$setting} must be a JSON boolean");
        }

        return $appData[$setting];
    }
}
