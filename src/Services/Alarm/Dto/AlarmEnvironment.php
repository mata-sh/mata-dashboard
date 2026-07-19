<?php

declare(strict_types=1);

namespace Mata\Services\Alarm\Dto;

/**
 * Environment context assigned to an alarm, with display representations.
 */
enum AlarmEnvironment: string
{
    case Live = 'live';
    case Test = 'test';
    case Dev = 'dev';
    case Staging = 'staging';

    /**
     * Get uppercase badge label for this environment.
     */
    public function getLabel(): string
    {
        return strtoupper($this->value);
    }

    /**
     * Get badge background color for this environment.
     */
    public function getBadgeColor(): string
    {
        return match ($this) {
            self::Live => '#098f00',
            self::Test => '#f59e0b',
            self::Dev => '#207bdc',
            self::Staging => '#663399',
        };
    }

    /**
     * Create from a loosely formatted string (aliases, casing), or null if unknown.
     */
    public static function tryFromString(?string $environment): ?self
    {
        return match (strtolower(trim((string) $environment))) {
            'live', 'prod', 'production' => self::Live,
            'test', 'testing' => self::Test,
            'dev', 'development' => self::Dev,
            'staging', 'stage', 'stg' => self::Staging,
            default => null,
        };
    }
}
