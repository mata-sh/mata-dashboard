<?php

declare(strict_types=1);

namespace Mata\Services\Monitor;

use Mata\Constants\NodeHealthState;

/**
 * Resolves the node health state from a health result.
 */
final class NodeHealthStateResolver
{
    /**
     * @param array<string,mixed> $result
     */
    public static function resolve(array $result): string
    {
        if (($result['maintenance_mode'] ?? false) === true) {
            return NodeHealthState::MAINTENANCE;
        }

        return match ($result['status'] ?? null) {
            NodeHealthState::HEALTHY => NodeHealthState::HEALTHY,
            NodeHealthState::UNHEALTHY => NodeHealthState::UNHEALTHY,
            default => NodeHealthState::UNREACHABLE,
        };
    }

    /**
     * Prevent instantiation - this is a static helper.
     */
    private function __construct() {}
}
