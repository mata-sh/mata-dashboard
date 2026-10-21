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
    public const MENU_FILE = 'menu.json';

    /** @param list<string> $validKeys */
    public function __construct(
        private array $validKeys,
        bool $fallbackOnFailure = true,
    ) {
        parent::__construct($fallbackOnFailure);
    }

    protected function fileName(): string
    {
        return self::MENU_FILE;
    }

    protected function problemType(): string
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
