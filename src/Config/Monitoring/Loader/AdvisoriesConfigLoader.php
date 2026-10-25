<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring\Loader;

use Mata\Config\Env;
use Mata\Config\Monitoring\Section\AdvisoriesConfig;

/**
 * Loads the optional advisories.json: security advisory sources, filters and the digest.
 * Without it, advisory polling is off and the Advisories menu entry is hidden.
 *
 * @extends AbstractJsonConfigLoader<AdvisoriesConfig>
 */
final class AdvisoriesConfigLoader extends AbstractJsonConfigLoader
{
    public const ADVISORIES_FILE = 'advisories.json';

    protected function fileName(): string
    {
        return self::ADVISORIES_FILE;
    }

    protected function problemType(): string
    {
        return 'advisories_config_invalid';
    }

    protected function isOptional(): bool
    {
        return true;
    }

    protected function defaultSection(): AdvisoriesConfig
    {
        return AdvisoriesConfig::empty();
    }

    protected function parse(array $config, string $filePath): AdvisoriesConfig
    {
        return AdvisoriesConfig::fromArray($config, Env::get(...), $this->reportInvalidEntry(...));
    }
}
