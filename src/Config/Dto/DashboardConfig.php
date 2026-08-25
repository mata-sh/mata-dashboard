<?php

declare(strict_types=1);

namespace Mata\Config\Dto;

use InvalidArgumentException;

/**
 * Immutable DTO representing dashboard display configuration.
 *
 * Contains settings for dashboard layout including server grid columns
 * and container width.
 */
final class DashboardConfig
{
    public function __construct(
        public readonly int $serverColumns,
        public readonly int $containerWidth,
    ) {
        if ($serverColumns < 1) {
            throw new InvalidArgumentException('dashboard.server_columns must be at least 1.');
        }
        if ($containerWidth < 320) {
            throw new InvalidArgumentException('dashboard.container_width must be at least 320.');
        }
    }
}
