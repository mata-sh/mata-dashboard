<?php

declare(strict_types=1);

namespace Mata\Services\Alarm\Dto;

use InvalidArgumentException;
use Mata\Utils\TypeConverter;

/**
 * Value object representing rate limits for a channel.
 *
 * Encapsulates per-minute, per-hour, and per-day ceilings used by the
 * alarm dispatcher to decide how many alarms can be delivered.
 */
final class AlarmLimit
{
    public const DEFAULT_PER_MINUTE = 20;
    public const DEFAULT_PER_HOUR = 120;
    public const DEFAULT_PER_DAY = 500;

    private int $perMinute;
    private int $perHour;
    private int $perDay;

    public function __construct(int $perMinute, int $perHour, int $perDay)
    {
        if ($perMinute < 0 || $perHour < 0 || $perDay < 0) {
            throw new InvalidArgumentException('Alarm limits must not be negative.');
        }

        $this->perMinute = $perMinute;
        $this->perHour = $perHour;
        $this->perDay = $perDay;
    }

    /**
     * Create from associative array, using provided fallbacks when missing.
     *
     * @param array<string,mixed> $payload  array with per_minute, per_hour, per_day keys
     * @param null|AlarmLimit     $fallback fallback limits applied when key missing
     */
    public static function fromArray(array $payload, ?AlarmLimit $fallback = null): self
    {
        $fallbackPerMinute = $fallback !== null ? $fallback->perMinute : self::DEFAULT_PER_MINUTE;
        $fallbackPerHour = $fallback !== null ? $fallback->perHour : self::DEFAULT_PER_HOUR;
        $fallbackPerDay = $fallback !== null ? $fallback->perDay : self::DEFAULT_PER_DAY;

        $perMinute = TypeConverter::toInt($payload['per_minute'] ?? $fallbackPerMinute) ?? $fallbackPerMinute;
        $perHour = TypeConverter::toInt($payload['per_hour'] ?? $fallbackPerHour) ?? $fallbackPerHour;
        $perDay = TypeConverter::toInt($payload['per_day'] ?? $fallbackPerDay) ?? $fallbackPerDay;

        return new self($perMinute, $perHour, $perDay);
    }

    public function getPerMinute(): int
    {
        return $this->perMinute;
    }

    public function getPerHour(): int
    {
        return $this->perHour;
    }

    public function getPerDay(): int
    {
        return $this->perDay;
    }

    /**
     * Export to array for easy serialisation.
     *
     * @return array{per_minute:int,per_hour:int,per_day:int}
     */
    public function toArray(): array
    {
        return [
            'per_minute' => $this->perMinute,
            'per_hour' => $this->perHour,
            'per_day' => $this->perDay,
        ];
    }
}
