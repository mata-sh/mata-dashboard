<?php

declare(strict_types=1);

namespace Mata\Services\Alarm\Dto;

/** Normalized alarm threshold rule built from monitoring settings. */
final readonly class AlarmRule
{
    public const STRATEGY_EVENT_COUNT_WINDOW = 'event_count_window';
    public const STRATEGY_CONSECUTIVE_FAILURES = 'consecutive_failures';
    public const STRATEGY_IMMEDIATE = 'immediate';
    public const STRATEGY_INVALID = 'invalid';

    private function __construct(
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

        $strategy = $config['strategy'] ?? self::STRATEGY_EVENT_COUNT_WINDOW;
        if (!is_string($strategy)) {
            return self::invalid();
        }

        return match ($strategy) {
            self::STRATEGY_CONSECUTIVE_FAILURES => new self(
                $strategy,
                max(1, (int) ($config['required_count'] ?? 2)),
                max(1, (int) ($config['window_minutes'] ?? 10)),
            ),
            self::STRATEGY_EVENT_COUNT_WINDOW => self::eventCountWindow($config),
            default => self::invalid(),
        };
    }

    public static function immediate(): self
    {
        return new self(self::STRATEGY_IMMEDIATE, 1, 0);
    }

    public static function invalid(): self
    {
        return new self(self::STRATEGY_INVALID, 0, 0);
    }

    /** @param array<string,mixed> $config */
    private static function eventCountWindow(array $config): self
    {
        $required = (int) ($config['alarm_threshold_event_count'] ?? 1);
        if ($required <= 0) {
            return self::immediate();
        }

        return new self(
            self::STRATEGY_EVENT_COUNT_WINDOW,
            $required,
            max(1, (int) ($config['alarm_threshold_window_minutes'] ?? 720)),
        );
    }
}
