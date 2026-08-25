<?php

declare(strict_types=1);

namespace Mata\Services\Monitor\Dto;

use Mata\Config\Monitoring\Section\ProbesConfig;

/** Immutable runtime retry and backoff behavior for HTTP probes. */
final readonly class ProbePolicy
{
    /**
     * @var array<string,true>
     */
    private const RETRYABLE_FAILURE_KINDS = [
        'dns' => true,
        'connect' => true,
        'tls' => true,
        'timeout' => true,
    ];

    public function __construct(
        public int $timeoutSeconds,
        public int $maxRetries,
        public int $backoffBaseMs,
        public int $backoffMaxMs,
    ) {}

    public static function fromConfig(ProbesConfig $config): self
    {
        return new self(
            timeoutSeconds: $config->timeoutSeconds,
            maxRetries: $config->maxRetries,
            backoffBaseMs: $config->backoffBaseMs,
            backoffMaxMs: $config->backoffMaxMs,
        );
    }

    public function isRetryable(?string $failureKind): bool
    {
        return isset(self::RETRYABLE_FAILURE_KINDS[$failureKind ?? '']);
    }

    /**
     * Full-jitter backoff delay for a 1-based retry attempt, in microseconds.
     *
     * delay = random(0, min(backoffMaxMs, backoffBaseMs * 2^(attempt-1)))
     */
    public function backoffMicros(int $attempt): int
    {
        if ($this->backoffMaxMs <= 0 || $this->backoffBaseMs <= 0) {
            return 0;
        }

        $retryIndex = max(0, $attempt - 1);
        $exponentialDelayMs = $this->backoffBaseMs * (2 ** $retryIndex);
        $delayCeilingMs = (int) min($this->backoffMaxMs, $exponentialDelayMs);

        return random_int(0, $delayCeilingMs) * 1000;
    }
}
