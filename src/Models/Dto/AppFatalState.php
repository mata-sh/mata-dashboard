<?php

declare(strict_types=1);

namespace Mata\Models\Dto;

final readonly class AppFatalState
{
    public function __construct(
        public int $serverId,
        public int $applicationId,
        public string $fatalCursor,
        public int $fatalCount,
    ) {}
}
