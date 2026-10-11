<?php

declare(strict_types=1);

namespace Mata\Models\Monitor;

final readonly class AppLibraryAuditState
{
    /** @param list<string> $advisoryIds Sorted advisory IDs reported by the last evaluated audit */
    public function __construct(
        public int $serverId,
        public int $applicationId,
        public array $advisoryIds,
    ) {}
}
