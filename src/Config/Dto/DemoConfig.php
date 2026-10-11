<?php

declare(strict_types=1);

namespace Mata\Config\Dto;

use InvalidArgumentException;

/** Demo data and public demo login; only read when APP_ENV=demo. */
final readonly class DemoConfig
{
    public function __construct(
        public ?int $seed = null,
        public ?string $loginUsername = null,
        public ?string $loginPassword = null,
    ) {
        if ($seed !== null && $seed < 0) {
            throw new InvalidArgumentException('demo.seed must be at least 0.');
        }
    }
}
