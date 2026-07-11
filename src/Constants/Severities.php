<?php

declare(strict_types=1);

namespace Mata\Constants;

/**
 * Severity levels in descending rank order, as used across audit normalization and aggregation.
 */
final class Severities
{
    /**
     * Severity levels in descending rank order.
     */
    public const ORDER = ['critical', 'high', 'medium', 'low', 'unknown'];

    /**
     * Prevent instantiation - this is a constants-only class.
     */
    private function __construct() {}
}
