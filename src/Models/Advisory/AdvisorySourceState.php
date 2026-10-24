<?php

declare(strict_types=1);

namespace Mata\Models\Advisory;

/** Poll bookkeeping of one source. Times are UTC `Y-m-d H:i:s`. */
final readonly class AdvisorySourceState
{
    public function __construct(
        public string $sourceKey,
        public ?string $baselineAt,
        public ?string $lastPolledAt,
        public ?string $lastSuccessAt,
        public int $consecutiveFailures,
        public ?string $lastError,
        public ?int $lastItemCount,
        public ?string $cursor,
    ) {}

    /** The first successful poll stores what the source already lists without notifying. */
    public function hasBaseline(): bool
    {
        return $this->baselineAt !== null;
    }
}
