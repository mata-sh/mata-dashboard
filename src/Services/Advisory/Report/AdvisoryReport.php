<?php

declare(strict_types=1);

namespace Mata\Services\Advisory\Report;

use DateTimeImmutable;
use Mata\Models\Advisory\Advisory;
use Mata\Services\Audit\Severity;

/**
 * A summary of advisories over a period, built by AdvisoryReportBuilder and rendered as
 * JSON, Markdown, HTML or PDF. Times are UTC.
 */
final readonly class AdvisoryReport
{
    public const SCHEMA_VERSION = 1;

    /**
     * @param array<string,int>                                  $severityCounts every Severity value, worst first
     * @param list<array<string,mixed>>                          $sources        per source: key, name, type, description, count, severity_counts, health
     * @param list<array{product:string,count:int,worst:string}> $topProducts
     * @param array<string,list<Advisory>>                       $bySeverity     advisories per Severity value, worst first, empty groups omitted
     * @param array<string,string>                               $sourceNames    source key => display name
     */
    public function __construct(
        public string $title,
        public DateTimeImmutable $generatedAt,
        public AdvisoryReportQuery $query,
        public int $total,
        public array $severityCounts,
        public int $unreviewed,
        public int $notified,
        public int $cveCount,
        public array $sources,
        public array $topProducts,
        public array $bySeverity,
        public array $sourceNames,
    ) {}

    /** @return list<Advisory> critical and high advisories, worst first */
    public function highlights(int $limit = 10): array
    {
        return array_slice([...($this->bySeverity[Severity::Critical->value] ?? []), ...($this->bySeverity[Severity::High->value] ?? [])], 0, $limit);
    }

    public function criticalOrHighCount(): int
    {
        return ($this->severityCounts[Severity::Critical->value] ?? 0) + ($this->severityCounts[Severity::High->value] ?? 0);
    }

    public function worstSeverity(): Severity
    {
        foreach (Severity::cases() as $severity) {
            if (($this->severityCounts[$severity->value] ?? 0) > 0) {
                return $severity;
            }
        }

        return Severity::Unknown;
    }

    public function sourceName(string $key): string
    {
        return $this->sourceNames[$key] ?? $key;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $advisories = [];
        foreach ($this->bySeverity as $group) {
            foreach ($group as $advisory) {
                $advisories[] = [
                    'id' => $advisory->id,
                    'source' => $advisory->sourceKey,
                    'source_name' => $this->sourceName($advisory->sourceKey),
                    'external_id' => $advisory->externalId,
                    'title' => $advisory->title,
                    'link' => $advisory->link,
                    'summary' => $advisory->summary,
                    'severity' => $advisory->severity->value,
                    'cvss' => $advisory->cvssScore,
                    'cve_ids' => $advisory->cveIds,
                    'products' => $advisory->products,
                    'matched_keywords' => $advisory->matchedKeywords,
                    'relevant' => $advisory->relevant,
                    'published_at' => self::isoTime($advisory->publishedAt),
                    'first_seen_at' => self::isoTime($advisory->firstSeenAt),
                    'notified_at' => self::isoTime($advisory->notifiedAt),
                    'event_id' => $advisory->eventId,
                    'review' => [
                        'status' => $advisory->reviewStatus->value,
                        'note' => $advisory->reviewNote,
                        'by' => $advisory->reviewedBy,
                        'at' => self::isoTime($advisory->reviewedAt),
                    ],
                ];
            }
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'title' => $this->title,
            'generated_at' => $this->generatedAt->format(DATE_ATOM),
            'query' => $this->query->toArray(),
            'totals' => [
                'advisories' => $this->total,
                'by_severity' => $this->severityCounts,
                'unreviewed' => $this->unreviewed,
                'notified' => $this->notified,
                'cves' => $this->cveCount,
                'worst_severity' => $this->worstSeverity()->value,
            ],
            'sources' => $this->sources,
            'top_products' => $this->topProducts,
            'advisories' => $advisories,
        ];
    }

    public static function isoTime(?string $utc): ?string
    {
        return $utc === null ? null : str_replace(' ', 'T', $utc) . 'Z';
    }
}
