<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring\Loader;

use Mata\Config\Monitoring\Section\AlarmRateLimitsConfig;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Loads limits.json into an AlarmRateLimitsConfig.
 *
 * Loaded before the aggregate because AlarmDirectProcessor needs these limits and
 * the server and threshold loaders need that processor. The aggregate receives the
 * resulting typed section after this bootstrap step.
 *
 * A malformed, missing, or unreadable file warns and yields built-in defaults; there
 * is no failure mode that stops the boot.
 */
final class AlarmRateLimitsConfigLoader extends AbstractJsonConfigLoader
{
    private LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    /** Takes the configuration root, matching the sibling loaders' signature. */
    public function load(string $configRoot): AlarmRateLimitsConfig
    {
        $payload = $this->decodeFile(rtrim($configRoot, '/') . '/limits.json');

        return AlarmRateLimitsConfig::fromArray(
            $payload,
            function (string $message): void {
                $this->logger->warning($message);
            },
        );
    }

    /** @return array<string,mixed> */
    private function decodeFile(string $limitsFile): array
    {
        if ($limitsFile === '' || !is_file($limitsFile)) {
            $this->logger->warning("Alarm limits file missing; falling back to defaults [file={$limitsFile}]");

            return [];
        }
        if (!is_readable($limitsFile)) {
            $this->logger->warning("Alarm limits file unreadable; falling back to defaults [file={$limitsFile}]");

            return [];
        }

        try {
            return $this->normalizeJson($this->readJsonObject($limitsFile));
        } catch (RuntimeException $exception) {
            $this->logger->warning("Alarm limits file malformed; falling back to defaults [file={$limitsFile}, error={$exception->getMessage()}]");

            return [];
        }
    }
}
