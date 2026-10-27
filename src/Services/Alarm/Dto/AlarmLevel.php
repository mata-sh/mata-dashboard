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
     * Get display presentation for this level: severity code, badge fill and accent color.
     * Colors are the dashboard's severity tokens (docs/development/ui-styleguide.md): the fill
     * carries white text, the accent is the lighter border shade used for text on dark surfaces.
     *
     * @return array{value:string,display_label:string,code:string,fill:string,accent:string}
     */
    public function getPresentation(): array
    {
        return match ($this) {
            self::Critical => [
                'value' => 'critical', 'display_label' => 'Critical', 'code' => 'SEV-1', 'fill' => '#c22a35', 'accent' => '#e05561',
            ],
            self::Error => [
                'value' => 'error', 'display_label' => 'Error', 'code' => 'SEV-2', 'fill' => '#cc5b0e', 'accent' => '#e98036',
            ],
            self::Warning => [
                'value' => 'warning', 'display_label' => 'Warning', 'code' => 'SEV-3', 'fill' => '#c19209', 'accent' => '#e2b30b',
            ],
            self::Info => [
                'value' => 'info', 'display_label' => 'Info', 'code' => 'SEV-4', 'fill' => '#2f6f7d', 'accent' => '#7fe3f0',
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
