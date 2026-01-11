<?php

declare(strict_types=1);

namespace Mata\Config\Dto;

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
    ) {}
}
