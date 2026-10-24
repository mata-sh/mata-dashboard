<?php

declare(strict_types=1);

namespace Mata\Controllers;

use DateTimeImmutable;
use DateTimeZone;
use Mata\Bootstrap\AdvisoryRuntime;
use Mata\Core\App;
use Mata\Http\Request;
use Mata\Http\Responses\HtmlResponse;
use Mata\Http\Responses\JsonResponse;
use Mata\Http\Responses\Response;
use Mata\Http\Routing\Endpoints;
use Mata\Models\Advisory\AdvisoryReviewStatus;
use Mata\Services\Advisory\Report\AdvisoryReportQuery;
use Mata\Services\Advisory\Report\AdvisoryReportRenderer;
use Mata\Services\View\AdvisoriesViewBuilder;
use Mata\Utils\Tools;
use Mata\Views\AdvisoriesRenderer;
use Monolog\Logger;
use Twig\Environment;

/**
 * Advisories page: source health, the advisory table, per-advisory detail with the raw
 * payload and review, and report downloads.
 */
class AdvisoryController extends BaseController
{
    private const TABLE_DAYS = [30, 90, 365];
    private const REPORT_MAX_DAYS = 3660;
    private const NOTE_LIMIT = 1000;

    private AdvisoriesRenderer $renderer;
    private AdvisoriesViewBuilder $viewBuilder;

    public function __construct(
        private App $app,
        Tools $tools,
        Environment $twig,
        Logger $logger,
        private Endpoints $endpoints,
        private AdvisoryRuntime $advisories,
        Request $request,
    ) {
        parent::__construct($request);
        $this->logger = $logger;
        $this->renderer = new AdvisoriesRenderer($twig, $logger, $tools, $app, $endpoints);
        $this->viewBuilder = new AdvisoriesViewBuilder($advisories->config, $advisories->advisories, $advisories->states);
    }

    public function renderOverview(): Response
    {
        return $this->executeWithErrorHandling(
            fn (): Response => new HtmlResponse($this->renderer->renderContainer(
                configured: $this->advisories->config->configured,
                sources: $this->advisories->config->configured ? $this->viewBuilder->sources(new DateTimeImmutable('now', new DateTimeZone('UTC'))) : [],
                endpoints: [
                    'advisories_render' => $this->endpoints->url('advisories_render'),
                    'advisories_json' => $this->endpoints->url('advisories_json'),
                    'advisory_detail' => $this->endpoints->url('advisory_detail_render', ['advisoryId' => '__ID__']),
                    'advisories_report' => $this->endpoints->url('advisories_report'),
                ],
                defaultDigestDays: $this->advisories->config->digest->everyDays,
            )),
            'Failed to load advisories',
            'Advisories container rendering',
        );
    }

    public function getAdvisoriesJson(): Response
    {
        return $this->executeWithErrorHandling(
            function (): Response {
                $days = (int) ($this->request->queryString('days') ?? 90);

                return new JsonResponse($this->viewBuilder->rows(in_array($days, self::TABLE_DAYS, true) ? $days : 90, $this->now()));
            },
            'Failed to retrieve advisories',
            'Advisories JSON API request',
        );
    }

    public function renderDetail(string $advisoryId): Response
    {
        return $this->executeWithErrorHandling(
            function () use ($advisoryId): Response {
                $advisory = $this->advisories->advisories->find((int) $advisoryId);
                $raw = $advisory === null ? null : $this->advisories->advisories->raw($advisory->id);
                if ($advisory === null || $raw === null) {
                    return $this->handleHtmlNotFound('Advisory', "advisory {$advisoryId}");
                }

                return new HtmlResponse($this->renderer->renderDetailModal(
                    $advisory,
                    $this->advisories->config->source($advisory->sourceKey)->name ?? $advisory->sourceKey,
                    $raw,
                    (string) $this->endpoints->url('advisory_review', ['advisoryId' => (string) $advisory->id]),
                ));
            },
            'Failed to load the advisory',
            'Advisory detail rendering',
        );
    }

    /** Stores the review and returns the refreshed modal; the table reloads on advisoryReviewed. */
    public function review(string $advisoryId): Response
    {
        return $this->executeWithErrorHandling(
            function () use ($advisoryId): Response {
                $status = AdvisoryReviewStatus::tryFrom($this->request->postString('status') ?? '');
                $note = trim($this->request->postString('note') ?? '');
                if ($status === null || mb_strlen($note) > self::NOTE_LIMIT) {
                    return $this->handleValidationError(['status' => 'new, reviewed or not_relevant', 'note' => 'at most 1000 characters']);
                }
                $updated = $this->advisories->advisories->review(
                    (int) $advisoryId,
                    $status,
                    $note === '' ? null : $note,
                    $this->app->auth->getUsername() ?? 'unknown',
                    $this->now()->format('Y-m-d H:i:s'),
                );
                if (!$updated) {
                    return $this->handleHtmlNotFound('Advisory', "advisory {$advisoryId}");
                }

                return $this->renderDetail($advisoryId)->addHeader('HX-Trigger', 'advisoryReviewed');
            },
            'Failed to save the review',
            'Advisory review',
        );
    }

    public function downloadReport(): Response
    {
        return $this->executeWithErrorHandling(
            function (): Response {
                $format = $this->request->queryString('format') ?? 'pdf';
                $days = (int) ($this->request->queryString('days') ?? 7);
                if (!in_array($format, AdvisoryReportRenderer::FORMATS, true) || $days < 1 || $days > self::REPORT_MAX_DAYS) {
                    return $this->handleValidationError(['format' => implode(', ', AdvisoryReportRenderer::FORMATS), 'days' => '1 to ' . self::REPORT_MAX_DAYS]);
                }

                $now = $this->now();
                $report = $this->advisories->reportBuilder()->build(
                    AdvisoryReportQuery::lastDays($days, $now, relevantOnly: $this->request->queryString('scope') !== 'all'),
                    $now,
                );
                $fileName = AdvisoryReportRenderer::fileName($report, $format);

                return new Response($this->advisories->reportRenderer()->render($report, $format), 200, [
                    'Content-Type' => AdvisoryReportRenderer::contentType($format),
                    'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
                    'Cache-Control' => 'no-store',
                    'X-Content-Type-Options' => 'nosniff',
                ]);
            },
            'Failed to build the advisory report',
            'Advisory report download',
        );
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
