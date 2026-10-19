<?php

declare(strict_types=1);

namespace Mata\Services\Status;

/**
 * Outcome of every bin/mata command and check; the value is the exit code (Nagios-style, so
 * Error is CRITICAL) and label() the `status` of its --json output.
 */
enum StatusLevel: int
{
    case Ok = 0;
    case Warning = 1;
    case Error = 2;
    case Unknown = 3;

    /** @param iterable<self> $levels */
    public static function worst(iterable $levels): self
    {
        $worst = self::Ok;
        foreach ($levels as $level) {
            if ($level->severity() > $worst->severity()) {
                $worst = $level;
            }
        }

        return $worst;
    }

    /** Severity for rollups: error, then unknown (data missing), then warning. */
    public function severity(): int
    {
        return match ($this) {
            self::Ok => 0,
            self::Warning => 1,
            self::Unknown => 2,
            self::Error => 3,
        };
    }

    public function label(): string
    {
        return strtolower($this->name);
    }
}
