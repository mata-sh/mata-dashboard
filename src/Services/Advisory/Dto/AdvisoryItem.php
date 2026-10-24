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
    ) {}
}
