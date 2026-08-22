<?php

declare(strict_types=1);

namespace Mata\Services\Monitor\Dto;

/**
 * Node health states used by health monitors and renderers.
 *
 * Backing values are rendered into the `node-health-*` CSS class by
 * templates/partials/cards/server-card.html.twig, so they must not change.
 */
enum NodeHealthState: string
{
    case Healthy = 'healthy';
    case Unhealthy = 'unhealthy';
    case Unreachable = 'unreachable';
    case Maintenance = 'maintenance';
    case Unknown = 'unknown';

    /**
     * Resolve a state from an untrusted node payload value.
     *
     * Unmapped and missing values degrade to Unknown rather than throwing, because
     * the value originates from a remote node's JSON response.
     */
    public static function fromNodePayload(?string $status): self
    {
        return self::tryFrom(strtolower((string) $status)) ?? self::Unknown;
    }

    /**
     * Human-readable label for the node health chip.
     */
    public function label(): string
    {
        // No default arm: adding a case without a label must fail static analysis.
        return match ($this) {
            self::Healthy => 'Healthy',
            self::Unhealthy => 'Unhealthy',
            self::Unreachable => 'Unreachable',
            self::Maintenance => 'Maintenance',
            self::Unknown => 'Health check failed',
        };
    }
}
