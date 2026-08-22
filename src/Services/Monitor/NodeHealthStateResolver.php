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
    public static function resolve(array $result): NodeHealthState
    {
        if (($result['maintenance_mode'] ?? false) === true) {
            return NodeHealthState::Maintenance;
        }

        $rawStatus = $result['status'] ?? null;
        $status = NodeHealthState::fromNodePayload(is_string($rawStatus) ? $rawStatus : null);

        // Only healthy and unhealthy are trusted as node-reported states. Anything
        // else means we could not establish one, which is reported as unreachable.
        return match ($status) {
            NodeHealthState::Healthy, NodeHealthState::Unhealthy => $status,
            default => NodeHealthState::Unreachable,
        };
    }

    /**
     * Prevent instantiation - this is a static helper.
     */
    private function __construct() {}
}
