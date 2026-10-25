<?php

declare(strict_types=1);

namespace Mata\Services\Advisory\Dto;

use DateTimeImmutable;
use Mata\Services\Audit\Severity;

/** An advisory as a fetcher normalized it, before filtering and storage. */
final readonly class AdvisoryItem
{
    /**
     * @param string       $externalId stable per source: feed guid or link, GHSA ID, Message-ID
     * @param list<string> $cveIds
     * @param list<string> $products   affected products or packages, with versions when known
     * @param string       $rawFormat  xml, json or eml
     * @param list<string> $aliases    other IDs of the same advisory at this source; the poller skips the item when one is stored
     */
    public function __construct(
        public string $externalId,
        public string $title,
        public ?string $link,
        public ?string $summary,
        public Severity $severity,
        public ?float $cvssScore,
        public array $cveIds,
        public array $products,
        public ?DateTimeImmutable $publishedAt,
        public string $rawFormat,
        public string $raw,
        public array $aliases = [],
    ) {}

    public function withRaw(string $raw): self
    {
        return new self(
            externalId: $this->externalId,
            title: $this->title,
            link: $this->link,
            summary: $this->summary,
            severity: $this->severity,
            cvssScore: $this->cvssScore,
            cveIds: $this->cveIds,
            products: $this->products,
            publishedAt: $this->publishedAt,
            rawFormat: $this->rawFormat,
            raw: $raw,
            aliases: $this->aliases,
        );
    }

    /** @return list<string> */
    public function ids(): array
    {
        return [$this->externalId, ...$this->aliases];
    }
}
