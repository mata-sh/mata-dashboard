<?php

declare(strict_types=1);

namespace Mata\Services\Alarm\Dto;

/** Normalized alarm threshold rule built from monitoring configuration. */
final readonly class AlarmRule
{
    public const STRATEGY_EVENT_COUNT_WINDOW = 'event_count_window';
    public const STRATEGY_CONSECUTIVE_FAILURES = 'consecutive_failures';
    public const STRATEGY_IMMEDIATE = 'immediate';
    public const STRATEGY_DISABLED = 'disabled';
    public const STRATEGY_INVALID = 'invalid';

    private function __construct(
        public bool $enabled,
        public string $strategy,
        public int $requiredCount,
        public int $windowMinutes,
    ) {}

    /** @param array<string,mixed> $config */
    public static function fromArray(array $config): self
    {
        if ($config === []) {
            return self::immediate();
        }
        if (array_key_exists('enabled', $config) && !is_bool($config['enabled'])) {
            return self::invalid();
        }
        if (($config['enabled'] ?? true) === false) {
            return self::disabled();
        }

        $strategy = $config['strategy'] ?? self::STRATEGY_EVENT_COUNT_WINDOW;
        if (!is_string($strategy)) {
            return self::invalid();
        }

        return match ($strategy) {
            self::STRATEGY_CONSECUTIVE_FAILURES => self::consecutiveFailures($config),
            self::STRATEGY_EVENT_COUNT_WINDOW => self::eventCountWindow($config),
            self::STRATEGY_IMMEDIATE => self::immediate(),
            default => self::invalid(),
        };
    }

    public static function immediate(): self
    {
        return new self(true, self::STRATEGY_IMMEDIATE, 1, 0);
    }

    public static function disabled(): self
    {
        return new self(false, self::STRATEGY_DISABLED, 0, 0);
    }

    public static function invalid(): self
    {
        return new self(true, self::STRATEGY_INVALID, 0, 0);
    }

    /** @param array<string,mixed> $config */
    private static function consecutiveFailures(array $config): self
    {
        $requiredCount = $config['required_count'] ?? 2;
        $windowMinutes = $config['window_minutes'] ?? 10;
        if (!is_int($requiredCount) || $requiredCount < 1 || !is_int($windowMinutes) || $windowMinutes < 1) {
            return self::invalid();
        }

        return new self(true, self::STRATEGY_CONSECUTIVE_FAILURES, $requiredCount, $windowMinutes);
    }

    /** @param array<string,mixed> $config */
    private static function eventCountWindow(array $config): self
    {
        $required = $config['alarm_threshold_event_count'] ?? 1;
        $windowMinutes = $config['alarm_threshold_window_minutes'] ?? 720;
        if (!is_int($required) || $required < 0 || !is_int($windowMinutes) || $windowMinutes < 1) {
            return self::invalid();
        }
        if ($required === 0) {
            return self::immediate();
        }

        return new self(true, self::STRATEGY_EVENT_COUNT_WINDOW, $required, $windowMinutes);
    }
}
