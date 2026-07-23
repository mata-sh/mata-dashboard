<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring;

use Mata\Services\Event\EventRecorderService;
use RuntimeException;

/**
 * Loads optional dashboard top-menu visibility overrides from menu.json.
 *
 * The file is a flat map of menu item key => bool, where the boolean toggles
 * whether that navigation entry is shown in the dashboard topbar. It is fully
 * optional: when absent, the built-in MenuBuilder defaults apply and no warning
 * is emitted. When present, each entry is validated individually - unknown menu
 * keys and non-boolean values are dropped with a warning while the remaining
 * valid entries still take effect.
 *
 * Example menu.json:
 *   {
 *       "metrics": true,
 *       "databases": false
 *   }
 */
final class MenuConfigLoader extends AbstractJsonConfigLoader implements MonitoringConfigLoaderInterface
{
    /**
     * @param EventRecorderService $eventRecorder     Records validation warnings
     * @param list<string>         $validKeys         Menu item keys that may be overridden
     * @param bool                 $fallbackOnFailure Whether malformed files fall back to defaults
     */
    public function __construct(
        private EventRecorderService $eventRecorder,
        private array $validKeys,
        private bool $fallbackOnFailure = true,
    ) {}

    public function key(): string
    {
        return 'menu';
    }

    /** @return array<string,bool> */
    public function load(string $configRoot): array
    {
        return $this->loadMenuConfig(rtrim($configRoot, '/') . '/menu.json');
    }

    /** @return array<string,bool> */
    public function loadMenuConfig(string $filePath): array
    {
        // Optional config: a missing file simply keeps the built-in menu defaults.
        if (!is_file($filePath)) {
            return $this->getDefaultConfig();
        }

        try {
            $config = $this->normalizeJson($this->readJsonObject($filePath));
        } catch (RuntimeException $exception) {
            $this->reportLoadFailure($filePath, $exception->getMessage());

            throw new MonitoringConfigLoadException('menu.json invalid', previous: $exception);
        }

        return $this->validate($config);
    }

    public function shouldFallback(): bool
    {
        return $this->fallbackOnFailure;
    }

    /**
     * No overrides by default; the built-in MenuBuilder flags decide visibility.
     *
     * @return array<string,bool>
     */
    public function getDefaultConfig(): array
    {
        return [];
    }

    /**
     * Validate entries individually, warning on and dropping invalid ones.
     *
     * @param array<string,mixed> $config
     *
     * @return array<string,bool>
     */
    private function validate(array $config): array
    {
        $overrides = [];
        foreach ($config as $key => $value) {
            $key = (string) $key;
            if (!in_array($key, $this->validKeys, true)) {
                $this->reportInvalidEntry("menu.json references unknown menu item '{$key}' -> ignored");

                continue;
            }
            if (!is_bool($value)) {
                $this->reportInvalidEntry("menu.json entry '{$key}' must be a boolean -> ignored");

                continue;
            }
            $overrides[$key] = $value;
        }

        return $overrides;
    }

    private function reportLoadFailure(string $filePath, string $reason): void
    {
        $this->eventRecorder->recordEvent(
            type: 'menu_config_invalid',
            message: "Could not load {$filePath}; falling back to menu defaults: {$reason}",
            level: 'warning',
        );
    }

    private function reportInvalidEntry(string $message): void
    {
        $this->eventRecorder->recordEvent(
            type: 'menu_config_invalid_entry',
            message: $message,
            level: 'warning',
        );
    }
}
