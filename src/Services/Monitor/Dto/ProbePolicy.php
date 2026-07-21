<?php

declare(strict_types=1);

namespace Mata\Services\Monitor\Dto;

/** Immutable timeout, retry, and backoff policy for HTTP probes. */
final readonly class ProbePolicy
{
    private const DEFAULT_TIMEOUT_SECONDS = 5;
    private const DEFAULT_MAX_RETRIES = 1;
    private const DEFAULT_BACKOFF_BASE_MS = 500;
    private const DEFAULT_BACKOFF_MAX_MS = 1500;

    /**
     * Failure kinds (as emitted by {@see \Mata\Services\Foundation\ProbeClient})
     * that a retry may recover from, stored as a set for O(1) lookup.
     *
     * Retryability follows failure semantics and is fixed in code rather than
     * configuration, because the failure vocabulary is owned by the classifier.
     * `tls` stays retryable on purpose: the classifier folds transient SSL
     * handshake timeouts (curl errno 28) into `tls`, so excluding it would also
     * suppress retries for genuinely transient failures. Deterministic outcomes
     * (`http`, `invalid_response`, `unknown`) are intentionally omitted.
     *
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

    /** Default policy: 5s timeout, one retry, and 0.5-1.5s jitter. */
    public static function default(): self
    {
        return new self(
            timeoutSeconds: self::DEFAULT_TIMEOUT_SECONDS,
            maxRetries: self::DEFAULT_MAX_RETRIES,
            backoffBaseMs: self::DEFAULT_BACKOFF_BASE_MS,
            backoffMaxMs: self::DEFAULT_BACKOFF_MAX_MS,
        );
    }

    /**
     * Build a clamped policy from raw settings, falling back to {@see default()} per field.
     *
     * @param array<string,mixed> $config
     */
    public static function fromArray(array $config): self
    {
        $default = self::default();
        $timeoutSeconds = max(1, (int) ($config['timeout_seconds'] ?? $default->timeoutSeconds));
        $maxRetries = max(0, (int) ($config['max_retries'] ?? $default->maxRetries));
        $backoffBaseMs = max(0, (int) ($config['backoff_base_ms'] ?? $default->backoffBaseMs));
        $backoffMaxMs = max(
            $backoffBaseMs,
            (int) ($config['backoff_max_ms'] ?? $default->backoffMaxMs),
        );

        return new self(
            timeoutSeconds: $timeoutSeconds,
            maxRetries: $maxRetries,
            backoffBaseMs: $backoffBaseMs,
            backoffMaxMs: $backoffMaxMs,
        );
    }

    /** @return array<string,mixed> */
    public function toConfigArray(): array
    {
        return [
            'timeout_seconds' => $this->timeoutSeconds,
            'max_retries' => $this->maxRetries,
            'backoff_base_ms' => $this->backoffBaseMs,
            'backoff_max_ms' => $this->backoffMaxMs,
        ];
    }

    public function isRetryable(?string $failureKind): bool
    {
        return isset(self::RETRYABLE_FAILURE_KINDS[$failureKind ?? '']);
    }

    /**
     * Full-jitter backoff delay for a 1-based retry attempt, in microseconds.
     *
     * delay = random(0, min(backoffMaxMs, backoffBaseMs * 2^(attempt-1))), so the
     * sleep window widens per retry but never exceeds the configured ceiling and
     * never synchronizes across concurrent probes.
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
