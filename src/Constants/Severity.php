<?php

declare(strict_types=1);

namespace Mata\Constants;

/**
 * Severity levels used across audit normalization and aggregation.
 *
 * Case declaration order is the rank order, descending: the first case that has a
 * non-zero count is the worst severity. Do not reorder cases.
 */
enum Severity: string
{
    case Critical = 'critical';
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';
    case Unknown = 'unknown';

    /**
     * Backing values in descending rank order.
     *
     * @return list<'critical'|'high'|'low'|'medium'|'unknown'>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
