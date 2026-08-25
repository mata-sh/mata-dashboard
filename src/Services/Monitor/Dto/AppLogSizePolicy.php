<?php

declare(strict_types=1);

namespace Mata\Services\Monitor\Dto;

use InvalidArgumentException;

/** Resolves the log-size monitoring switch. */
final readonly class AppLogSizePolicy
{
    public function __construct(private bool $serverEnabled) {}

    /** @param array<string,mixed> $serverConfig */
    public static function fromServerConfig(array $serverConfig): self
    {
        return new self(self::serverConfigFlag($serverConfig, 'logsize_monitoring_enabled'));
    }

    /** @param array<string,mixed> $appData */
    public function isEnabled(array $appData): bool
    {
        return $this->serverEnabled && self::appSetting($appData, 'logsize');
    }

    /**
     * @param array<string,mixed> $config
     *
     * @throws InvalidArgumentException When the configured value is not a JSON boolean
     */
    private static function serverConfigFlag(array $config, string $configKey): bool
    {
        if (!array_key_exists($configKey, $config)) {
            return true;
        }
        if (!is_bool($config[$configKey])) {
            throw new InvalidArgumentException("{$configKey} must be a JSON boolean when configured");
        }

        return $config[$configKey];
    }

    /**
     * @param array<string,mixed> $appData
     */
    private static function appSetting(array $appData, string $setting): bool
    {
        return AppMonitoringSettings::fromAppPayload($appData)->isEnabled($setting);
    }
}
