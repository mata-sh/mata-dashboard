<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring\Section;

use InvalidArgumentException;
use Mata\Config\ConfigObject;

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
        $probes = ConfigObject::fromValue($raw, 'probes');
        $timeoutSeconds = $probes->integer('timeout_seconds', self::DEFAULT_TIMEOUT_SECONDS, minimum: 1);
        $maxRetries = $probes->integer('max_retries', self::DEFAULT_MAX_RETRIES, minimum: 0);
        $backoffBaseMs = $probes->integer('backoff_base_ms', self::DEFAULT_BACKOFF_BASE_MS, minimum: 0);
        $backoffMaxMs = $probes->integer('backoff_max_ms', self::DEFAULT_BACKOFF_MAX_MS, minimum: 0);
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
