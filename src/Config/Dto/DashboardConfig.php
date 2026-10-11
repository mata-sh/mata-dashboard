<?php

declare(strict_types=1);

namespace Mata\Config\Dto;

use InvalidArgumentException;

/**
 * Immutable DTO representing dashboard display configuration.
 *
 * Contains settings for dashboard layout including server grid columns
 * and container width, and how often the server cards reload.
 */
final class DashboardConfig
{
    public function __construct(
        public readonly int $serverColumns,
        public readonly int $containerWidth,
        /** Seconds between automatic server card reloads; 0 disables them. */
        public readonly int $autoRefreshSeconds = 60,
    ) {
        if ($serverColumns < 1) {
            throw new InvalidArgumentException('dashboard.server_columns must be at least 1.');
        }
        if ($containerWidth < 320) {
            throw new InvalidArgumentException('dashboard.container_width must be at least 320.');
        }
        if ($autoRefreshSeconds < 0) {
            throw new InvalidArgumentException('dashboard.auto_refresh_seconds must be 0 or more.');
        }
    }
}
