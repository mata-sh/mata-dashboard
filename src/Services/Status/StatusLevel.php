<?php

declare(strict_types=1);

namespace Mata\Services\Status;

/** Nagios-style levels; the value is the exit code. */
enum StatusLevel: int
{
    case Ok = 0;
    case Warning = 1;
    case Critical = 2;
    case Unknown = 3;

    /** Severity for the fleet rollup: critical, then unknown (data missing), then warning. */
    public function severity(): int
    {
        return match ($this) {
            self::Ok => 0,
            self::Warning => 1,
            self::Unknown => 2,
            self::Critical => 3,
        };
    }

    public function label(): string
    {
        return strtolower($this->name);
    }
}
