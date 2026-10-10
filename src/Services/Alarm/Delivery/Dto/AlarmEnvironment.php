<?php

declare(strict_types=1);

namespace Mata\Services\Alarm\Delivery\Dto;

/**
 * Environment context assigned to an alarm, with display representations.
 */
enum AlarmEnvironment: string
{
    case Prod = 'prod';
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
            self::Prod => '#1bbf0f',
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
            'prod', 'live' => self::Prod,
            'test', 'testing' => self::Test,
            'dev', 'development' => self::Dev,
            'staging', 'stage', 'stg' => self::Staging,
            default => null,
        };
    }
}
