<?php

declare(strict_types=1);

namespace Mata\Services\Advisory\Report;

use DateTimeImmutable;
use Mata\Services\Audit\Severity;

/** Which advisories a report covers: occurred (published, else first seen) in [since, until). */
final readonly class AdvisoryReportQuery
{
    /** @param ?list<string> $sourceKeys null for every configured source */
    public function __construct(
        public DateTimeImmutable $since,
        public DateTimeImmutable $until,
        public ?array $sourceKeys = null,
        public bool $relevantOnly = true,
        public Severity $minSeverity = Severity::Low,
        public bool $includeNotRelevant = false,
    ) {}

    public static function lastDays(int $days, DateTimeImmutable $now, bool $relevantOnly = true): self
    {
        return new self($now->modify("-{$days} days"), $now, relevantOnly: $relevantOnly);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'since' => $this->since->format(DATE_ATOM),
            'until' => $this->until->format(DATE_ATOM),
            'sources' => $this->sourceKeys,
            'relevant_only' => $this->relevantOnly,
            'min_severity' => $this->minSeverity->value,
            'include_not_relevant_reviews' => $this->includeNotRelevant,
        ];
    }
}
