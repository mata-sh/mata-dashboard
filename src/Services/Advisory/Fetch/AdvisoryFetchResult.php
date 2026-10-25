<?php

declare(strict_types=1);

namespace Mata\Services\Advisory\Fetch;

use Mata\Services\Advisory\Dto\AdvisoryItem;

final readonly class AdvisoryFetchResult
{
    /**
     * @param list<AdvisoryItem> $items
     * @param ?string            $cursor where the next fetch continues (IMAP UID); null keeps the stored one
     */
    public function __construct(
        public array $items,
        public ?string $cursor = null,
    ) {}
}
