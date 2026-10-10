<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring\Loader;

use Mata\Config\Monitoring\Section\AlarmRateLimitsConfig;
use Mata\Services\Event\EventManager;
use RuntimeException;

/**
 * Loads the optional limits.json into an AlarmRateLimitsConfig.
 *
 * Loaded before the aggregate because AlarmDirectProcessor needs these limits and
 * the servers loader needs that processor. The aggregate receives the resulting
 * typed section after this bootstrap step.
 *
 * A missing file silently yields built-in defaults. A malformed or unreadable file
 * warns and yields defaults; there is no failure mode that stops the boot.
 */
final class AlarmRateLimitsConfigLoader extends AbstractJsonConfigLoader
{
    public function __construct(private EventManager $events) {}

    /** Takes the configuration root, matching the sibling loaders' signature. */
    public function load(string $configRoot): AlarmRateLimitsConfig
    {
        $limitsFile = rtrim($configRoot, '/') . '/limits.json';
        if (!is_file($limitsFile)) {
            return $this->defaultConfig();
        }

        try {
            $payload = $this->normalizeJson($this->readJsonObject($limitsFile));
        } catch (RuntimeException $exception) {
            $this->reportProblem(
                $this->events,
                'limits_config_invalid',
                "Could not load {$limitsFile}; falling back to default limits: {$exception->getMessage()}",
            );

            return $this->defaultConfig();
        }

        return AlarmRateLimitsConfig::fromArray($payload, function (string $message): void {
            $this->reportProblem($this->events, 'limits_config_invalid_entry', $message);
        });
    }

    private function defaultConfig(): AlarmRateLimitsConfig
    {
        return AlarmRateLimitsConfig::fromArray([], static function (string $message): void {});
    }
}
