<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring\Section;

use InvalidArgumentException;
use Mata\Config\ConfigValueValidator;

/** One channel's per-minute, per-hour, and per-day alarm delivery limits. */
final readonly class AlarmRateLimit
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

    public static function default(): self
    {
        return new self(self::DEFAULT_PER_MINUTE, self::DEFAULT_PER_HOUR, self::DEFAULT_PER_DAY);
    }

    /**
     * @param array<string,mixed> $payload
     */
    public static function fromArray(
        array $payload,
        ?self $fallback = null,
        string $path = 'limits.channels.default',
    ): self {
        $fallback ??= self::default();

        return new self(
            ConfigValueValidator::integer(
                array_key_exists('per_minute', $payload) ? $payload['per_minute'] : $fallback->perMinute,
                "{$path}.per_minute",
                minimum: 0,
            ),
            ConfigValueValidator::integer(
                array_key_exists('per_hour', $payload) ? $payload['per_hour'] : $fallback->perHour,
                "{$path}.per_hour",
                minimum: 0,
            ),
            ConfigValueValidator::integer(
                array_key_exists('per_day', $payload) ? $payload['per_day'] : $fallback->perDay,
                "{$path}.per_day",
                minimum: 0,
            ),
        );
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

    /** @return array{per_minute:int,per_hour:int,per_day:int} */
    public function toArray(): array
    {
        return [
            'per_minute' => $this->perMinute,
            'per_hour' => $this->perHour,
            'per_day' => $this->perDay,
        ];
    }
}
