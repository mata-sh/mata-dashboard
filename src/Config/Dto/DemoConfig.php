<?php

declare(strict_types=1);

namespace Mata\Config\Dto;

use InvalidArgumentException;

/** Demo data settings; only read when APP_ENV=demo. */
final readonly class DemoConfig
{
    public function __construct(
        public ?int $seed = null,
    ) {
        if ($seed !== null && $seed < 0) {
            throw new InvalidArgumentException('demo.seed must be at least 0.');
        }
    }
}
