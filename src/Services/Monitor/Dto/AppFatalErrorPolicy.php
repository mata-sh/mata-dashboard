<?php

declare(strict_types=1);

namespace Mata\Services\Monitor\Dto;

use InvalidArgumentException;

/** Resolves the fatal-error monitoring switch. */
final readonly class AppFatalErrorPolicy
{
    public function __construct(private bool $serverEnabled) {}

    /** @param array<string,mixed> $serverConfig */
    public static function fromServerConfig(array $serverConfig): self
    {
        return new self(self::serverSetting($serverConfig, 'error_monitoring_enabled'));
    }

    /** @param array<string,mixed> $appData */
    public function isEnabled(array $appData): bool
    {
        return $this->serverEnabled && self::appSetting($appData, 'loganalysis');
    }

    /**
     * @param array<string,mixed> $config
     *
     * @throws InvalidArgumentException When the configured value is not a JSON boolean
     */
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

    /**
     * @param array<string,mixed> $appData
     */
    private static function appSetting(array $appData, string $setting): bool
    {
        return AppMonitoringSettings::fromAppPayload($appData)->isEnabled($setting);
    }
}
