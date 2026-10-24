<?php

declare(strict_types=1);

namespace Mata\Views;

use DOMDocument;
use Mata\Models\Advisory\Advisory;
use Mata\Models\Advisory\AdvisoryReviewStatus;
use Mata\Services\Advisory\Report\AdvisoryReport;
use Mata\Services\Advisory\Report\AdvisoryReportPresenter;

/** Advisories page: source panels, the advisory table, report downloads and the detail modal. */
final class AdvisoriesRenderer extends BaseRenderer
{
    /**
     * @param list<array<string,mixed>> $sources
     * @param array<string,?string>     $endpoints
     */
    public function renderContainer(bool $configured, array $sources, array $endpoints, int $defaultDigestDays): string
    {
        return $this->twig->render('partials/containers/advisories-container.html.twig', [
            'configured' => $configured,
            'sources' => $sources,
            'endpoints' => $endpoints,
            'report_days' => array_values(array_unique([7, 30, 90, $defaultDigestDays])),
            'default_days' => $defaultDigestDays,
            'icons' => [
                'help' => $this->tools->icon('help-circle', 14),
                'download' => $this->tools->icon('download', 12),
                'refresh' => $this->tools->icon('refresh-cw', 12),
                'external' => $this->tools->icon('external-link', 12),
            ],
        ]);
    }

    /** @param array{format:string,raw:string} $raw */
    public function renderDetailModal(Advisory $advisory, string $sourceName, array $raw, string $reviewUrl): string
    {
        return $this->twig->render('partials/modals/advisory-modal.html.twig', [
            'advisory' => $advisory,
            'source_name' => $sourceName,
            'severity' => AdvisoryReportPresenter::severity($advisory->severity->value) + ['value' => $advisory->severity->value],
            'occurred_at' => AdvisoryReport::isoTime($advisory->occurredAt()),
            'first_seen_at' => AdvisoryReport::isoTime($advisory->firstSeenAt),
            'raw' => $this->prettyRaw($raw),
            'review_statuses' => array_map(static fn (AdvisoryReviewStatus $status): array => ['value' => $status->value, 'label' => $status->label()], AdvisoryReviewStatus::cases()),
            'review_url' => $reviewUrl,
            'icons' => [
                'close' => $this->tools->icon('x', 14),
                'external' => $this->tools->icon('external-link', 12),
            ],
        ]);
    }

    /**
     * Raw payload as stored, JSON re-indented and XML formatted when parseable.
     *
     * @param array{format:string,raw:string} $raw
     *
     * @return array{format:string,content:string}
     */
    private function prettyRaw(array $raw): array
    {
        $content = $raw['raw'];
        if ($raw['format'] === 'xml') {
            $document = new DOMDocument();
            $document->preserveWhiteSpace = false;
            $document->formatOutput = true;
            if (@$document->loadXML($content, LIBXML_NONET) === true) {
                $content = (string) $document->saveXML($document->documentElement);
            }
        }

        return ['format' => $raw['format'], 'content' => $content];
    }
}
