<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring\Loader;

use Mata\Config\Monitoring\Section\AlarmRateLimitsConfig;
use RuntimeException;

/**
 * Loads the optional limits.json into an AlarmRateLimitsConfig.
 *
 * Loaded before the aggregate because AlarmDirectProcessor needs these limits, and
 * the processor must exist before forced config alarms are recorded. The aggregate
 * receives the resulting typed section after this bootstrap step.
 *
 * A missing file silently yields built-in defaults. A malformed or unreadable file
 * warns and yields defaults; there is no failure mode that stops the boot.
 */
final class AlarmRateLimitsConfigLoader extends AbstractJsonConfigLoader
{
    /**
     * Takes the configuration root, matching the sibling loaders' signature.
     *
     * @return ConfigLoadResult<AlarmRateLimitsConfig>
     */
    public function load(string $configRoot): ConfigLoadResult
    {
        $limitsFile = rtrim($configRoot, '/') . '/limits.json';
        if (!is_file($limitsFile)) {
            return $this->resultWithProblems(AlarmRateLimitsConfig::default());
        }

        try {
            $payload = $this->normalizeJson($this->readJsonObject($limitsFile));
        } catch (RuntimeException $exception) {
            $this->report(
                'limits_config_invalid',
                "Could not load {$limitsFile}; falling back to default limits: {$exception->getMessage()}",
            );

            return $this->resultWithProblems(AlarmRateLimitsConfig::default());
        }

        // fromArray() skips invalid entries; each skip lands in this load's problems.
        $reportInvalidEntry = fn (string $message) => $this->report('limits_config_invalid_entry', $message);
        $config = AlarmRateLimitsConfig::fromArray($payload, $reportInvalidEntry);

        return $this->resultWithProblems($config);
    }
}
