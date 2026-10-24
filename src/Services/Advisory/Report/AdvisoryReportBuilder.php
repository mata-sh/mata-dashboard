<?php

declare(strict_types=1);

namespace Mata\Services\Advisory\Report;

use DateTimeImmutable;
use DateTimeZone;
use Mata\Config\Monitoring\Section\AdvisoriesConfig;
use Mata\Config\Monitoring\Section\Advisory\AdvisorySource;
use Mata\Models\Advisory\Advisory;
use Mata\Models\Advisory\AdvisoryRepository;
use Mata\Models\Advisory\AdvisoryReviewStatus;
use Mata\Models\Advisory\AdvisorySourceState;
use Mata\Models\Advisory\AdvisorySourceStateRepository;
use Mata\Services\Advisory\AdvisoryRelevanceFilter;
use Mata\Services\Audit\Severity;

/**
 * Aggregates stored advisories into an AdvisoryReport: counts per severity and source,
 * the most affected products, and the advisories grouped by severity. Baseline advisories
 * (stored by a source's first poll) count like any other: they occurred in the period.
 */
final class AdvisoryReportBuilder
{
    private const TOP_PRODUCTS = 10;

    public function __construct(
        private AdvisoriesConfig $config,
        private AdvisoryRepository $advisories,
        private AdvisorySourceStateRepository $states,
    ) {}

    public function build(AdvisoryReportQuery $query, DateTimeImmutable $now): AdvisoryReport
    {
        $sources = $query->sourceKeys === null
            ? $this->config->sources
            : array_intersect_key($this->config->sources, array_flip($query->sourceKeys));
        $advisories = array_values(array_filter(
            $this->advisories->findSince(
                since: self::utc($query->since),
                until: self::utc($query->until),
                sourceKeys: array_keys($sources),
                relevantOnly: $query->relevantOnly,
                includeNotRelevantReviews: $query->includeNotRelevant,
            ),
            static fn (Advisory $advisory): bool => AdvisoryRelevanceFilter::meetsSeverity($advisory->severity, $query->minSeverity)
                && ($advisory->severity !== Severity::Unknown || $query->minSeverity === Severity::Low),
        ));

        $severityCounts = array_fill_keys(Severity::values(), 0);
        $bySeverity = array_fill_keys(Severity::values(), []);
        $perSource = array_fill_keys(array_keys($sources), []);
        $cves = [];
        $unreviewed = $notified = 0;
        foreach ($advisories as $advisory) {
            ++$severityCounts[$advisory->severity->value];
            $bySeverity[$advisory->severity->value][] = $advisory;
            $perSource[$advisory->sourceKey][] = $advisory;
            $unreviewed += $advisory->reviewStatus === AdvisoryReviewStatus::New ? 1 : 0;
            $notified += $advisory->eventId !== null ? 1 : 0;
            foreach ($advisory->cveIds as $cve) {
                $cves[$cve] = true;
            }
        }
        $states = $this->states->all();

        return new AdvisoryReport(
            title: 'Security advisory report',
            generatedAt: $now->setTimezone(new DateTimeZone('UTC')),
            query: $query,
            total: count($advisories),
            severityCounts: $severityCounts,
            unreviewed: $unreviewed,
            notified: $notified,
            cveCount: count($cves),
            sources: array_values(array_map(
                fn (AdvisorySource $source): array => $this->sourceSummary($source, $perSource[$source->key], $states[$source->key] ?? null),
                $sources,
            )),
            topProducts: $this->topProducts($advisories),
            bySeverity: array_filter($bySeverity),
            sourceNames: array_map(static fn (AdvisorySource $source): string => $source->name, $this->config->sources),
        );
    }

    /**
     * @param list<Advisory> $advisories
     *
     * @return array<string,mixed>
     */
    private function sourceSummary(AdvisorySource $source, array $advisories, ?AdvisorySourceState $state): array
    {
        $counts = array_fill_keys(Severity::values(), 0);
        foreach ($advisories as $advisory) {
            ++$counts[$advisory->severity->value];
        }

        return [
            'key' => $source->key,
            'name' => $source->name,
            'type' => $source->type->value,
            'type_label' => $source->type->label(),
            'description' => $source->description,
            'url' => $source->url,
            'notify' => $source->notify,
            'count' => count($advisories),
            'severity_counts' => $counts,
            'health' => self::health($source, $state),
            'last_success_at' => AdvisoryReport::isoTime($state?->lastSuccessAt),
            'consecutive_failures' => $state->consecutiveFailures ?? 0,
            'last_error' => $state?->lastError,
        ];
    }

    /** ok, failing, never_polled or disabled. */
    public static function health(AdvisorySource $source, ?AdvisorySourceState $state): string
    {
        return match (true) {
            !$source->enabled => 'disabled',
            $state === null || ($state->lastSuccessAt === null && $state->consecutiveFailures === 0) => 'never_polled',
            $state->consecutiveFailures > 0 => 'failing',
            default => 'ok',
        };
    }

    /**
     * Products by the number of advisories naming them; versions are dropped so
     * "ext-standard <8.2.34" and "ext-standard <8.3.35" count as one.
     *
     * @param list<Advisory> $advisories
     *
     * @return list<array{product:string,count:int,worst:string}>
     */
    private function topProducts(array $advisories): array
    {
        $rank = array_flip(Severity::values());
        $products = [];
        foreach ($advisories as $advisory) {
            $names = [];
            foreach ($advisory->products as $product) {
                $name = trim(preg_split('/\s+(?=[<>=~^(]|\d)|\s+\(/u', $product, 2)[0] ?? $product);
                if ($name !== '') {
                    $names[mb_strtolower($name)] = $name;
                }
            }
            foreach ($names as $key => $name) {
                $entry = $products[$key] ?? ['product' => $name, 'count' => 0, 'worst' => Severity::Unknown->value];
                ++$entry['count'];
                if ($rank[$advisory->severity->value] < $rank[$entry['worst']]) {
                    $entry['worst'] = $advisory->severity->value;
                }
                $products[$key] = $entry;
            }
        }
        usort($products, static fn (array $a, array $b): int => [$b['count'], $rank[$a['worst']], $a['product']] <=> [$a['count'], $rank[$b['worst']], $b['product']]);

        return array_slice($products, 0, self::TOP_PRODUCTS);
    }

    private static function utc(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
