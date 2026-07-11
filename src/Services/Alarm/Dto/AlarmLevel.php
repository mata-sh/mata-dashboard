<?php

declare(strict_types=1);

namespace Mata\Services\Alarm\Dto;

/**
 * Alarm severity levels with display representations.
 */
enum AlarmLevel: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Error = 'error';
    case Critical = 'critical';

    /**
     * Get emoji indicator for this level.
     */
    public function getEmoji(): string
    {
        return match ($this) {
            self::Critical => '🔴',
            self::Error => '🟠',
            self::Warning => '🟡',
            self::Info => '🔵',
        };
    }

    /**
     * Create from string value with fallback to Info.
     */
    public static function fromString(string $level): self
    {
        return match (strtolower($level)) {
            'critical' => self::Critical,
            'error' => self::Error,
            'warning' => self::Warning,
            'info' => self::Info,
            default => self::Info,
        };
    }
}
