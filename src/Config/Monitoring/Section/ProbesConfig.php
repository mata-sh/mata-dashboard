<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring\Section;

use InvalidArgumentException;
use Mata\Config\ConfigValueValidator;

/** HTTP probe timeout, retry, and backoff configuration. */
final readonly class ProbesConfig
{
    private const DEFAULT_TIMEOUT_SECONDS = 5;
    private const DEFAULT_MAX_RETRIES = 1;
    private const DEFAULT_BACKOFF_BASE_MS = 500;
    private const DEFAULT_BACKOFF_MAX_MS = 1500;

    public function __construct(
        public int $timeoutSeconds,
        public int $maxRetries,
        public int $backoffBaseMs,
        public int $backoffMaxMs,
    ) {}

    public static function default(): self
    {
        return new self(
            timeoutSeconds: self::DEFAULT_TIMEOUT_SECONDS,
            maxRetries: self::DEFAULT_MAX_RETRIES,
            backoffBaseMs: self::DEFAULT_BACKOFF_BASE_MS,
            backoffMaxMs: self::DEFAULT_BACKOFF_MAX_MS,
        );
    }

    /** @param array<string,mixed> $raw */
    public static function fromArray(array $raw): self
    {
        $default = self::default();
        $timeoutSeconds = ConfigValueValidator::integer(
            array_key_exists('timeout_seconds', $raw) ? $raw['timeout_seconds'] : $default->timeoutSeconds,
            'probes.timeout_seconds',
            minimum: 1,
        );
        $maxRetries = ConfigValueValidator::integer(
            array_key_exists('max_retries', $raw) ? $raw['max_retries'] : $default->maxRetries,
            'probes.max_retries',
            minimum: 0,
        );
        $backoffBaseMs = ConfigValueValidator::integer(
            array_key_exists('backoff_base_ms', $raw) ? $raw['backoff_base_ms'] : $default->backoffBaseMs,
            'probes.backoff_base_ms',
            minimum: 0,
        );
        $backoffMaxMs = ConfigValueValidator::integer(
            array_key_exists('backoff_max_ms', $raw) ? $raw['backoff_max_ms'] : $default->backoffMaxMs,
            'probes.backoff_max_ms',
            minimum: 0,
        );
        if ($backoffMaxMs < $backoffBaseMs) {
            throw new InvalidArgumentException('probes.backoff_max_ms must be at least probes.backoff_base_ms.');
        }

        return new self($timeoutSeconds, $maxRetries, $backoffBaseMs, $backoffMaxMs);
    }

    /** @return array{timeout_seconds:int,max_retries:int,backoff_base_ms:int,backoff_max_ms:int} */
    public function toArray(): array
    {
        return [
            'timeout_seconds' => $this->timeoutSeconds,
            'max_retries' => $this->maxRetries,
            'backoff_base_ms' => $this->backoffBaseMs,
            'backoff_max_ms' => $this->backoffMaxMs,
        ];
    }
}
