<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring\Loader;

use Mata\Config\Monitoring\Loader\Dto\ConfigLoadResult;
use Mata\Config\Monitoring\Section\MenuConfig;
use RuntimeException;

/** Loads optional dashboard top-menu visibility overrides. */
final class MenuConfigLoader extends AbstractJsonConfigLoader
{
    /** @param list<string> $validKeys */
    public function __construct(
        private array $validKeys,
        private bool $fallbackOnFailure = true,
    ) {}

    /** @return ConfigLoadResult<MenuConfig> */
    public function load(string $configRoot): ConfigLoadResult
    {
        try {
            return $this->loadMenuConfig(rtrim($configRoot, '/') . '/menu.json');
        } catch (MonitoringConfigLoadException $exception) {
            if (!$this->fallbackOnFailure) {
                throw $exception;
            }

            return new ConfigLoadResult($this->getDefaultConfig(), $exception->problems);
        }
    }

    /** @return ConfigLoadResult<MenuConfig> */
    public function loadMenuConfig(string $filePath): ConfigLoadResult
    {
        if (!is_file($filePath)) {
            return $this->result($this->getDefaultConfig());
        }

        try {
            $config = $this->readJsonArray($filePath);
        } catch (RuntimeException $exception) {
            $this->reportLoadFailure($filePath, $exception->getMessage());

            throw $this->rejected('menu.json invalid', $exception);
        }

        $reportInvalidEntry = fn (string $message) => $this->report('menu_config_invalid_entry', $message);
        $menu = MenuConfig::fromArray($config, $this->validKeys, $reportInvalidEntry);

        return $this->result($menu);
    }

    public function getDefaultConfig(): MenuConfig
    {
        return MenuConfig::empty();
    }

    private function reportLoadFailure(string $filePath, string $reason): void
    {
        $this->report(
            'menu_config_invalid',
            "Could not load {$filePath}; falling back to menu defaults: {$reason}",
        );
    }
}
