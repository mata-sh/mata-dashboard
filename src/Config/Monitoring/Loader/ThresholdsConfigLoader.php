<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring\Loader;

use Mata\Config\Monitoring\Section\ThresholdsConfig;

/**
 * Loads thresholds.json: metric thresholds and alarm candidate windows.
 *
 * @extends AbstractJsonConfigLoader<ThresholdsConfig>
 */
final class ThresholdsConfigLoader extends AbstractJsonConfigLoader
{
    public const THRESHOLDS_FILE = 'thresholds.json';

    protected function fileName(): string
    {
        return self::THRESHOLDS_FILE;
    }

    protected function loadFailureType(): string
    {
        return 'thresholds_config_invalid';
    }

    protected function defaultSection(): ThresholdsConfig
    {
        return ThresholdsConfig::default();
    }

    protected function parse(array $config, string $filePath): ThresholdsConfig
    {
        return ThresholdsConfig::fromArray($config, $this->reportInvalidEntry(...));
    }
}
