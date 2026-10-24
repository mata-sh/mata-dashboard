<?php

declare(strict_types=1);

namespace Mata\Services\Advisory\Dto;

final readonly class AdvisoryRelevance
{
    /** @param list<string> $matchedKeywords */
    public function __construct(
        public bool $relevant,
        public array $matchedKeywords,
    ) {}
}
