<?php

declare(strict_types=1);

namespace Mata\Models\Advisory;

use Mata\Services\Audit\Severity;

/** One stored advisory without its raw payload. Times are UTC `Y-m-d H:i:s`. */
final readonly class Advisory
{
    /**
     * @param list<string> $cveIds
     * @param list<string> $products        affected products or packages, with versions when the source has them
     * @param list<string> $matchedKeywords source keywords found in the advisory
     */
    public function __construct(
        public int $id,
        public string $sourceKey,
        public string $externalId,
        public string $title,
        public ?string $link,
        public ?string $summary,
        public Severity $severity,
        public ?float $cvssScore,
        public array $cveIds,
        public array $products,
        public array $matchedKeywords,
        public bool $relevant,
        public bool $baseline,
        public ?string $publishedAt,
        public string $firstSeenAt,
        public ?string $notifiedAt,
        public ?int $eventId,
        public AdvisoryReviewStatus $reviewStatus,
        public ?string $reviewNote,
        public ?string $reviewedBy,
        public ?string $reviewedAt,
    ) {}

    /** Publication time, or when the dashboard first saw it for sources without one. */
    public function occurredAt(): string
    {
        return $this->publishedAt ?? $this->firstSeenAt;
    }
}
