<?php

declare(strict_types=1);

namespace Mata\Services\View;

use DateTimeImmutable;
use DateTimeZone;
use Mata\Config\Monitoring\Section\AdvisoriesConfig;
use Mata\Models\Advisory\Advisory;
use Mata\Models\Advisory\AdvisoryRepository;
use Mata\Models\Advisory\AdvisorySourceStateRepository;
use Mata\Services\Advisory\AdvisorySourceHealth;
use Mata\Services\Advisory\Report\AdvisoryReport;
use Mata\Services\Advisory\Report\AdvisoryReportPresenter;

/** View data of the Advisories page: source health panels and the advisory table rows. */
final class AdvisoriesViewBuilder
{
    public function __construct(
        private AdvisoriesConfig $config,
        private AdvisoryRepository $advisories,
        private AdvisorySourceStateRepository $states,
    ) {}

    /** @return list<array<string,mixed>> */
    public function sources(DateTimeImmutable $now): array
    {
        $states = $this->states->all();
        $totals = $this->advisories->sourceTotals();
        $rows = [];
        foreach ($this->config->sources as $key => $source) {
            $state = $states[$key] ?? null;
            $health = AdvisorySourceHealth::of($source, $state, $now, $this->config->staleAfterMinutes);
            $rows[] = [
                'key' => $key,
                'name' => $source->name,
                'description' => $source->description,
                'type' => $source->type->label(),
                'url' => $source->url,
                'enabled' => $source->enabled,
                'notify' => $source->notify,
                'interval' => $source->pollIntervalMinutes,
                'keywords' => $source->keywords,
                'min_severity' => $source->minSeverity->value,
                'health' => $health->value,
                'health_label' => $health->label(),
                'last_success' => self::display($state?->lastSuccessAt),
                'last_polled' => self::display($state?->lastPolledAt),
                'failures' => $state->consecutiveFailures ?? 0,
                'error' => $state?->lastError,
                'totals' => $totals[$key] ?? ['total' => 0, 'relevant' => 0, 'unreviewed' => 0, 'latest' => null],
            ];
        }

        return $rows;
    }

    /** @return list<array<string,mixed>> table rows, newest first */
    public function rows(int $days, DateTimeImmutable $now): array
    {
        $since = $now->setTimezone(new DateTimeZone('UTC'))->modify("-{$days} days")->format('Y-m-d H:i:s');
        $names = array_map(static fn ($source): string => $source->name, $this->config->sources);

        return array_map(static fn (Advisory $advisory): array => [
            'id' => $advisory->id,
            'datetime' => $advisory->occurredAt(),
            'source' => $names[$advisory->sourceKey] ?? $advisory->sourceKey,
            'source_key' => $advisory->sourceKey,
            'severity' => $advisory->severity->value,
            'severity_label' => AdvisoryReportPresenter::severity($advisory->severity->value)['label'],
            'cvss' => $advisory->cvssScore,
            'title' => $advisory->title,
            'link' => $advisory->link,
            'cves' => implode(' ', $advisory->cveIds),
            'products' => implode('; ', $advisory->products),
            'keywords' => implode(', ', $advisory->matchedKeywords),
            'relevant' => $advisory->relevant,
            'baseline' => $advisory->baseline,
            'notified' => $advisory->eventId !== null,
            'review_status' => $advisory->reviewStatus->value,
            'review_label' => $advisory->reviewStatus->label(),
            'review_note' => $advisory->reviewNote,
        ], $this->advisories->findSince($since, sourceKeys: array_keys($this->config->sources)));
    }

    private static function display(?string $utc): ?string
    {
        $iso = AdvisoryReport::isoTime($utc);

        return $iso === null ? null : substr(str_replace('T', ' ', $iso), 0, 16) . ' UTC';
    }
}
