<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring\Section;

use InvalidArgumentException;
use Mata\Config\Monitoring\ConfigObject;

/** One channel's per-minute, per-hour, and per-day alarm delivery limits. */
final readonly class AlarmRateLimit
{
    public const DEFAULT_PER_MINUTE = 20;
    public const DEFAULT_PER_HOUR = 120;
    public const DEFAULT_PER_DAY = 500;

    public function __construct(
        private int $perMinute,
        private int $perHour,
        private int $perDay,
    ) {}

    public static function default(): self
    {
        return new self(self::DEFAULT_PER_MINUTE, self::DEFAULT_PER_HOUR, self::DEFAULT_PER_DAY);
    }

    /**
     * Limits missing from $payload come from $fallback.
     *
     * @throws InvalidArgumentException when $payload is not an object or a limit is invalid
     */
    public static function fromArray(
        mixed $payload,
        ?self $fallback = null,
        string $path = 'limits.channels.default',
    ): self {
        $fallback ??= self::default();
        $limits = ConfigObject::fromValue($payload, $path);

        return new self(
            $limits->integer('per_minute', $fallback->perMinute, minimum: 0),
            $limits->integer('per_hour', $fallback->perHour, minimum: 0),
            $limits->integer('per_day', $fallback->perDay, minimum: 0),
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
