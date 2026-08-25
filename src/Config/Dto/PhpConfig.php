<?php

declare(strict_types=1);

namespace Mata\Config\Dto;

use InvalidArgumentException;

/**
 * Immutable DTO representing PHP runtime configuration.
 *
 * Contains PHP-specific settings including error display and logging levels.
 */
final class PhpConfig
{
    public function __construct(
        public readonly int $displayErrors,
        public readonly string $logLevel,
    ) {
        if ($displayErrors < 0 || $displayErrors > 1) {
            throw new InvalidArgumentException('php.display_errors must be 0 or 1.');
        }
    }
}
