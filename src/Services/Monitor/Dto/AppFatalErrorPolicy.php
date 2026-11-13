<?php

declare(strict_types=1);

namespace Mata\Services\Monitor\Dto;

use Mata\Config\Monitoring\Section\ServerConfig;

/** Resolves the fatal-error monitoring switch. */
final readonly class AppFatalErrorPolicy
{
    public function __construct(private bool $serverEnabled) {}

    public static function fromServerConfig(ServerConfig $serverConfig): self
    {
        return new self($serverConfig->errorMonitoringEnabled);
    }

    /** @param array<string,mixed> $appData */
    public function isEnabled(array $appData): bool
    {
        return $this->serverEnabled && self::appSetting($appData, 'loganalysis');
    }

    /**
     * @param array<string,mixed> $appData
     */
    private static function appSetting(array $appData, string $setting): bool
    {
        return AppMonitoringSettings::fromAppPayload($appData)->isEnabled($setting);
    }
}
