<?php

declare(strict_types=1);

namespace Mata\Constants;

/**
 * Node health states used by health monitors and renderers.
 */
final class NodeHealthState
{
    public const HEALTHY = 'healthy';
    public const UNHEALTHY = 'unhealthy';
    public const UNREACHABLE = 'unreachable';
    public const MAINTENANCE = 'maintenance';
    public const UNKNOWN = 'unknown';

    /**
     * Prevent instantiation - this is a constants-only class.
     */
    private function __construct() {}
}
