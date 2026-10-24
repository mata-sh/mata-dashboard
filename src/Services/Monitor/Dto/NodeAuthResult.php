<?php

declare(strict_types=1);

namespace Mata\Services\Monitor\Dto;

/** Outcome of the signed request, with an operator hint for known failure causes. */
final readonly class NodeAuthResult
{
    public function __construct(
        public NodeAuthState $state,
        public ?string $error = null,
        public ?string $hint = null,
    ) {}
}
