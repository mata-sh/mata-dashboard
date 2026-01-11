<?php

declare(strict_types=1);

namespace Mata\Services\Alert\Dto;

/**
 * Alert severity levels with display representations.
 */
enum AlertLevel: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Critical = 'critical';

    /**
     * Get emoji indicator for this level.
     */
    public function getEmoji(): string
    {
        return match ($this) {
            self::Critical => '🔴',
            self::Warning => '🟠',
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
            'warning' => self::Warning,
            'info' => self::Info,
            default => self::Info,
        };
    }
}
