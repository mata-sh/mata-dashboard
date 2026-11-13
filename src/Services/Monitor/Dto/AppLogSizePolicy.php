<?php

declare(strict_types=1);

namespace Mata\Services\Monitor\Dto;

use Mata\Config\Monitoring\Section\ServerConfig;

/** Resolves the log-size monitoring switch. */
final readonly class AppLogSizePolicy
{
    public function __construct(private bool $serverEnabled) {}

    public static function fromServerConfig(ServerConfig $serverConfig): self
    {
        return new self($serverConfig->logsizeMonitoringEnabled);
    }

    /** @param array<string,mixed> $appData */
    public function isEnabled(array $appData): bool
    {
        return $this->serverEnabled && self::appSetting($appData, 'logsize');
    }

    /**
     * @param array<string,mixed> $appData
     */
    private static function appSetting(array $appData, string $setting): bool
    {
        return AppMonitoringSettings::fromAppPayload($appData)->isEnabled($setting);
    }
}
