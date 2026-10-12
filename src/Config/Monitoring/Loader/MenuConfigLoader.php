<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring\Loader;

use Mata\Config\Monitoring\Section\MenuConfig;

/**
 * Loads the optional menu.json: dashboard top-menu visibility overrides.
 *
 * @extends AbstractJsonConfigLoader<MenuConfig>
 */
final class MenuConfigLoader extends AbstractJsonConfigLoader
{
    /** @param list<string> $validKeys */
    public function __construct(
        private array $validKeys,
        bool $fallbackOnFailure = true,
    ) {
        parent::__construct($fallbackOnFailure);
    }

    protected function fileName(): string
    {
        return 'menu.json';
    }

    protected function loadFailureType(): string
    {
        return 'menu_config_invalid';
    }

    protected function isOptional(): bool
    {
        return true;
    }

    protected function defaultSection(): MenuConfig
    {
        return MenuConfig::empty();
    }

    protected function parse(array $config, string $filePath): MenuConfig
    {
        return MenuConfig::fromArray($config, $this->validKeys, $this->reportInvalidEntry(...));
    }
}
