<?php

declare(strict_types=1);

namespace Mata\Config\Dto;

/**
 * Immutable DTO representing general application configuration.
 *
 * Contains application-wide settings including maintenance mode,
 * IP whitelisting, access control settings, monitoring flags, and security headers.
 */
final class GeneralConfig
{
    /**
     * @param array<int,string> $allowedIps     List of IP addresses allowed to access application endpoints
     * @param list<string>      $trustedProxies Exact IP addresses or CIDR ranges allowed to set forwarding headers
     */
    public function __construct(
        public readonly string $organizationName,
        public readonly bool $maintenanceModeEnabled,
        public readonly bool $ipWhitelistEnabled,
        public readonly array $allowedIps,
        public readonly bool $alarmsEnabled,
        public readonly bool $eventsEnabled,
        public readonly string $appName = 'MATA',
        public readonly bool $securityHeadersEnabled = true,
        public readonly array $trustedProxies = [],
        public readonly bool $hstsEnabled = false,
    ) {}
}
