<?php

declare(strict_types=1);

namespace Watcher\Config\Dto;

/**
 * Immutable DTO representing general application configuration.
 *
 * Contains application-wide settings including maintenance mode,
 * IP blocking, access control settings, and monitoring flags.
 */
final class GeneralConfig
{
    /**
     * @param array<int,string> $devAllowedIps List of IP addresses allowed in development mode
     */
    public function __construct(
        public readonly string $organizationName,
        public readonly bool $maintenanceModeEnabled,
        public readonly bool $devBlockIpsEnabled,
        public readonly array $devAllowedIps,
        public readonly bool $alarmsEnabled,
        public readonly bool $eventsEnabled,
    ) {}
}
