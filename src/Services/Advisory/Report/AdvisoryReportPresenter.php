<?php

declare(strict_types=1);

namespace Mata\Services\Advisory\Report;

use DateTimeImmutable;
use DateTimeZone;
use Mata\Models\Advisory\Advisory;
use Mata\Models\Advisory\AdvisoryReviewStatus;
use Mata\Services\Audit\Severity;

/**
 * Template data shared by the Markdown, HTML and PDF reports, so every format shows the same
 * numbers in the same words. Colors are the severity tokens of docs/development/ui-styleguide.md.
 */
final class AdvisoryReportPresenter
{
    /** @var array<string,array{label:string,emoji:string,fill:string,border:string,tint:string}> */
    private const SEVERITY = [
        'critical' => ['label' => 'Critical', 'emoji' => '🔴', 'fill' => '#c22a35', 'border' => '#e05561', 'tint' => '#f09595'],
        'high' => ['label' => 'High', 'emoji' => '🟡', 'fill' => '#c05a1d', 'border' => '#e0854a', 'tint' => '#f0b27b'],
        'medium' => ['label' => 'Medium', 'emoji' => '🔵', 'fill' => '#9c7c14', 'border' => '#c4a53a', 'tint' => '#e3ce7a'],
        'low' => ['label' => 'Low', 'emoji' => '🔵', 'fill' => '#2e7d43', 'border' => '#4da767', 'tint' => '#7bd88f'],
        'unknown' => ['label' => 'Unrated', 'emoji' => '⚪', 'fill' => '#4a5060', 'border' => '#6b7280', 'tint' => '#9a9a9a'],
    ];

    /** Stands in for the worst severity of a report without advisories. */
    private const QUIET = ['label' => 'Quiet', 'emoji' => '🟢', 'fill' => '#2e7d43', 'border' => '#4da767', 'tint' => '#7bd88f'];

    /** @var array<string,array{label:string,color:string}> */
    private const HEALTH = [
        'ok' => ['label' => 'OK', 'color' => '#7bd88f'],
        'failing' => ['label' => 'Failing', 'color' => '#f09595'],
        'never_polled' => ['label' => 'Not polled yet', 'color' => '#9a9a9a'],
        'disabled' => ['label' => 'Disabled', 'color' => '#5a6172'],
    ];

    private const SUMMARY_LIMIT = 320;
    private const PRODUCT_LIMIT = 4;

    public function __construct(
        private string $applicationName,
        private ?string $dashboardUrl = null,
    ) {}

    /** @return array<string,mixed> */
    public function present(AdvisoryReport $report): array
    {
        $since = $report->query->since;
        $until = $report->query->until;
        $days = max(1, (int) round(($until->getTimestamp() - $since->getTimestamp()) / 86400));
        $total = max(1, $report->total);

        $severityRows = [];
        foreach ($report->severityCounts as $value => $count) {
            if ($value === Severity::Unknown->value && $count === 0) {
                continue;
            }
            $severityRows[] = self::severity($value) + ['value' => $value, 'count' => $count, 'percent' => (int) round($count * 100 / $total)];
        }

        $groups = [];
        foreach ($report->bySeverity as $value => $advisories) {
            $groups[] = [
                'severity' => self::severity($value) + ['value' => $value],
                'count' => count($advisories),
                'items' => array_map(fn (Advisory $advisory): array => $this->item($report, $advisory), $advisories),
            ];
        }

        return [
            'branding' => $this->applicationName,
            'title' => $report->title,
            'generated_at' => $report->generatedAt->format('Y-m-d H:i') . ' UTC',
            'period' => [
                'since' => self::day($since),
                'until' => self::day($until),
                'days' => $days,
                'label' => $days === 1 ? 'last 24 hours' : "last {$days} days",
            ],
            'scope' => $this->scope($report),
            'worst' => self::worst($report),
            'totals' => [
                'advisories' => $report->total,
                'critical_high' => $report->criticalOrHighCount(),
                'unreviewed' => $report->unreviewed,
                'notified' => $report->notified,
                'cves' => $report->cveCount,
                'sources' => count($report->sources),
            ],
            'severity_rows' => $severityRows,
            'sources' => array_map(static fn (array $source): array => $source + [
                'health_label' => self::HEALTH[$source['health']]['label'],
                'health_color' => self::HEALTH[$source['health']]['color'],
                'last_success' => self::shortTime($source['last_success_at']),
            ], $report->sources),
            'top_products' => array_map(static fn (array $product): array => $product + ['severity' => self::severity($product['worst'])], $report->topProducts),
            'highlights' => array_map(fn (Advisory $advisory): array => $this->item($report, $advisory), $report->highlights()),
            'groups' => $groups,
            'dashboard_url' => $this->dashboardUrl,
            'empty' => $report->total === 0,
        ];
    }

    /** @return array{label:string,emoji:string,fill:string,border:string,tint:string} */
    public static function severity(string $value): array
    {
        return self::SEVERITY[$value] ?? self::SEVERITY['unknown'];
    }

    /** @return array{label:string,emoji:string,fill:string,border:string,tint:string} the worst severity, or "Quiet" without advisories */
    public static function worst(AdvisoryReport $report): array
    {
        return $report->total === 0 ? self::QUIET : self::severity($report->worstSeverity()->value);
    }

    /** A period bound as its UTC date. */
    public static function day(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d');
    }

    /** @return array<string,mixed> */
    private function item(AdvisoryReport $report, Advisory $advisory): array
    {
        $summary = $advisory->summary;
        if ($summary !== null && mb_strlen($summary) > self::SUMMARY_LIMIT) {
            $summary = rtrim(mb_substr($summary, 0, self::SUMMARY_LIMIT - 1)) . '…';
        }

        return [
            'id' => $advisory->id,
            'external_id' => $advisory->externalId,
            'source' => $report->sourceName($advisory->sourceKey),
            'title' => $advisory->title,
            'link' => $advisory->link,
            'summary' => $summary,
            'severity' => self::severity($advisory->severity->value) + ['value' => $advisory->severity->value],
            'cvss' => $advisory->cvssScore === null ? null : number_format($advisory->cvssScore, 1),
            'cves' => $advisory->cveIds,
            'products' => array_slice($advisory->products, 0, self::PRODUCT_LIMIT),
            'more_products' => max(0, count($advisory->products) - self::PRODUCT_LIMIT),
            'keywords' => $advisory->matchedKeywords,
            'date' => substr($advisory->occurredAt(), 0, 10),
            'review' => $advisory->reviewStatus === AdvisoryReviewStatus::New ? null : [
                'label' => $advisory->reviewStatus->label(),
                'note' => $advisory->reviewNote,
                'by' => $advisory->reviewedBy,
            ],
        ];
    }

    private function scope(AdvisoryReport $report): string
    {
        $parts = [$report->query->relevantOnly ? 'relevant advisories' : 'all advisories'];
        if ($report->query->minSeverity !== Severity::Low) {
            $parts[] = 'severity ' . strtolower(self::severity($report->query->minSeverity->value)['label']) . ' or higher';
        }
        if ($report->query->sourceKeys !== null) {
            $parts[] = 'sources: ' . implode(', ', array_map($report->sourceName(...), $report->query->sourceKeys));
        }
        if (!$report->query->includeNotRelevant) {
            $parts[] = 'without advisories reviewed as not relevant';
        }

        return implode(' · ', $parts);
    }

    private static function shortTime(?string $iso): ?string
    {
        return $iso === null ? null : substr(str_replace('T', ' ', $iso), 0, 16) . ' UTC';
    }
}
