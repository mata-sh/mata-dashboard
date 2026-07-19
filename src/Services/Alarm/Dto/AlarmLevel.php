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
     * Get display presentation for this level (severity code and accent color).
     *
     * @return array{value:string,display_label:string,code:string,accent:string}
     */
    public function getPresentation(): array
    {
        return match ($this) {
            self::Critical => [
                'value' => 'critical', 'display_label' => 'Critical', 'code' => 'SEV-1', 'accent' => '#ff3b4e',
            ],
            self::Error => [
                'value' => 'error', 'display_label' => 'Error', 'code' => 'SEV-2', 'accent' => '#ffb020',
            ],
            self::Warning => [
                'value' => 'warning', 'display_label' => 'Warning', 'code' => 'SEV-3', 'accent' => '#ffb020',
            ],
            self::Info => [
                'value' => 'info', 'display_label' => 'Info', 'code' => 'SEV-4', 'accent' => '#3fd0e0',
            ],
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
