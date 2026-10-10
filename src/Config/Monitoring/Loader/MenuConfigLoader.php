<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring\Loader;

use Mata\Config\Monitoring\Section\MenuConfig;
use Mata\Services\Event\EventManager;
use RuntimeException;

/** Loads optional dashboard top-menu visibility overrides. */
final class MenuConfigLoader extends AbstractJsonConfigLoader
{
    /** @param list<string> $validKeys */
    public function __construct(
        private EventManager $events,
        private array $validKeys,
        private bool $fallbackOnFailure = true,
    ) {}

    public function load(string $configRoot): MenuConfig
    {
        try {
            return $this->loadMenuConfig(rtrim($configRoot, '/') . '/menu.json');
        } catch (MonitoringConfigLoadException $exception) {
            if (!$this->fallbackOnFailure) {
                throw $exception;
            }

            return $this->getDefaultConfig();
        }
    }

    public function loadMenuConfig(string $filePath): MenuConfig
    {
        if (!is_file($filePath)) {
            return $this->getDefaultConfig();
        }

        try {
            $config = $this->normalizeJson($this->readJsonObject($filePath));
        } catch (RuntimeException $exception) {
            $this->reportLoadFailure($filePath, $exception->getMessage());

            throw new MonitoringConfigLoadException('menu.json invalid', previous: $exception);
        }

        return MenuConfig::fromArray($config, $this->validKeys, $this->reportInvalidEntry(...));
    }

    public function getDefaultConfig(): MenuConfig
    {
        return MenuConfig::empty();
    }

    private function reportLoadFailure(string $filePath, string $reason): void
    {
        $this->reportProblem(
            $this->events,
            'menu_config_invalid',
            "Could not load {$filePath}; falling back to menu defaults: {$reason}",
        );
    }

    private function reportInvalidEntry(string $message): void
    {
        $this->reportProblem($this->events, 'menu_config_invalid_entry', $message);
    }
}
