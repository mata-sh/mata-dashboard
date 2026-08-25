<?php

declare(strict_types=1);

namespace Mata\Config\Dto;

use InvalidArgumentException;

/** Timeout configuration for regular MATA Node API requests. */
final readonly class NodeApiConfig
{
    public const DEFAULT_TIMEOUT_SECONDS = 10;

    public function __construct(
        public readonly int $timeoutSeconds,
    ) {
        if ($timeoutSeconds < 1) {
            throw new InvalidArgumentException('node_api.timeout_seconds must be at least 1.');
        }
    }
}
