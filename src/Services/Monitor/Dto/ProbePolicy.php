<?php

declare(strict_types=1);

namespace Mata\Services\Monitor\Dto;

/**
 * Immutable retry/timeout policy shared by the availability and health monitors.
 *
 * Sourced from the `probes` monitoring settings; {@see default()} reproduces the
 * historical hardcoded behavior so an absent config file changes nothing.
 */
final readonly class ProbePolicy
{
    /** @param array<string,true> $retryableKinds failure kinds eligible for retry, stored as a set for O(1) lookup */
    public function __construct(
        public int $timeoutSeconds,
        public int $maxRetries,
        public int $backoffBaseMs,
        public int $backoffMaxMs,
        public array $retryableKinds,
    ) {}

    /** Defaults preserving the monitors' original 5s timeout, single retry, and 0.5-1.5s jitter. */
    public static function default(): self
    {
        return new self(5, 1, 500, 1500, ['dns' => true, 'connect' => true, 'tls' => true, 'timeout' => true]);
    }

    /**
     * Build a clamped policy from raw settings, falling back to {@see default()} per field.
     *
     * @param array<string,mixed> $config
     */
    public static function fromArray(array $config): self
    {
        $default = self::default();
        $base = max(0, (int) ($config['backoff_base_ms'] ?? $default->backoffBaseMs));
        $kinds = $config['retryable_kinds'] ?? null;

        return new self(
            timeoutSeconds: max(1, (int) ($config['timeout_seconds'] ?? $default->timeoutSeconds)),
            maxRetries: max(0, (int) ($config['max_retries'] ?? $default->maxRetries)),
            backoffBaseMs: $base,
            backoffMaxMs: max($base, (int) ($config['backoff_max_ms'] ?? $default->backoffMaxMs)),
            retryableKinds: is_array($kinds) && $kinds !== []
                ? array_fill_keys(array_map('strval', array_values($kinds)), true)
                : $default->retryableKinds,
        );
    }

    public function isRetryable(?string $failureKind): bool
    {
        return isset($this->retryableKinds[$failureKind ?? '']);
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
        if ($this->backoffMaxMs <= 0) {
            return 0;
        }
        $ceilingMs = (int) min($this->backoffMaxMs, $this->backoffBaseMs * (2 ** max(0, $attempt - 1)));

        return random_int(0, max(0, $ceilingMs)) * 1000;
    }
}
