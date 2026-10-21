<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring\Loader;

use Mata\Config\Monitoring\Section\AlarmRateLimitsConfig;

/**
 * Loads the optional limits.json: transport limits for queued alarm deliveries.
 *
 * Loaded before the aggregate because AlarmDirectProcessor needs these limits, and
 * the processor must exist before forced config alarms are recorded. The aggregate
 * receives the resulting typed section after this bootstrap step.
 *
 * @extends AbstractJsonConfigLoader<AlarmRateLimitsConfig>
 */
final class AlarmRateLimitsConfigLoader extends AbstractJsonConfigLoader
{
    public const LIMITS_FILE = 'limits.json';

    protected function fileName(): string
    {
        return self::LIMITS_FILE;
    }

    protected function loadFailureType(): string
    {
        return 'limits_config_invalid';
    }

    protected function isOptional(): bool
    {
        return true;
    }

    protected function defaultSection(): AlarmRateLimitsConfig
    {
        return AlarmRateLimitsConfig::default();
    }

    protected function parse(array $config, string $filePath): AlarmRateLimitsConfig
    {
        return AlarmRateLimitsConfig::fromArray($config, $this->reportInvalidEntry(...));
    }
}
