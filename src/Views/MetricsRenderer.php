<?php

declare(strict_types=1);

namespace Mata\Views;

use Exception;
use Mata\Core\App;
use Mata\Http\Responses\JsonResponse;
use Mata\Http\Routing\Endpoints;
use Mata\Utils\Tools;
use Monolog\Logger;
use Twig\Environment;

/**
 * Metrics renderer for visualization payloads.
 *
 * Combines MetricsViewBuilder output with Twig templates to deliver
 * modal-friendly chart content.
 */
class MetricsRenderer extends BaseRenderer
{
    public function __construct(
        Environment $twig,
        Logger $logger,
        Tools $tools,
        App $app,
        Endpoints $endpoints
    ) {
        parent::__construct($twig, $logger, $tools, $app, $endpoints);
    }

    /**
     * Render the metrics dashboard container with chart controls.
     *
     * @param array<string,mixed> $viewData
     */
    public function renderDashboardOverview(array $viewData): string
    {
        $templateData = [
            'chart' => is_array($viewData['chart'] ?? null) ? $viewData['chart'] : [],
            'metrics' => is_array($viewData['metrics'] ?? null) ? $viewData['metrics'] : [],
            'timespans' => is_array($viewData['timespans'] ?? null) ? $viewData['timespans'] : [],
            'servers' => is_array($viewData['servers'] ?? null) ? $viewData['servers'] : [],
            'icons' => $this->getDashboardIcons(),
        ];

        try {
            return $this->twig->render('partials/containers/metrics-dashboard-container.html.twig', $templateData);
        } catch (Exception $exception) {
            $this->logError($exception->getMessage(), 'MetricsDashboardContainer', [
                'data_keys' => array_keys($templateData),
            ]);

            return '<div class="notification is-danger">Failed to render metrics overview.</div>';
        }
    }

    /**
     * Render process count modal with daily granularity for a server.
     *
     * @param array<string,mixed> $payload
     */
    public function renderProcessCountModal(
        string $serverName,
        array $payload
    ): string {
        $series = is_array($payload['series'] ?? null) ? $payload['series'] : [];
        $filters = is_array($payload['filters'] ?? null) ? $payload['filters'] : [];
        $cache = is_array($payload['cache'] ?? null) ? $payload['cache'] : [];

        $metricKey = (string) ($series['metric'] ?? 'process_count');
        $metricSlug = str_replace('_', '-', $metricKey);
        $metricLabel = $this->getMetricLabel($metricKey);
        $range = (string) ($filters['range'] ?? $series['range'] ?? '30d');
        $granularity = (string) ($filters['granularity'] ?? $series['granularity'] ?? 'daily');

        $routeParams = [
            'server' => $serverName,
            'metric' => $metricSlug,
        ];

        $queryParams = [
            'range' => $range,
        ];

        $jsonParams = [
            'range' => $range,
            'granularity' => $granularity,
        ];

        $modalData = [
            'server' => [
                'name' => $serverName,
            ],
            'filters' => [
                'range' => $range,
                'granularity' => $granularity,
            ],
            'stats' => [
                'min' => $series['min'] ?? null,
                'max' => $series['max'] ?? null,
                'avg' => $series['avg'] ?? null,
                'unit' => $series['unit'] ?? '',
            ],
            'endpoints' => [
                'metrics_server' => $this->endpoints->url('metrics_server', $routeParams, $queryParams),
                'metrics_server_json' => $this->endpoints->url('metrics_server', $routeParams, $jsonParams),
            ],
            'chart' => [
                'element_id' => $this->generateHtmlId($serverName, $metricKey . '-chart'),
                'series' => $series,
                'metric_label' => $metricLabel,
                'metric' => $metricKey,
            ],
            'icons' => $this->getModalIcons(),
            'cache' => $cache,
        ];

        try {
            return $this->twig->render('partials/modals/metrics-process-count-modal.html.twig', $modalData);
        } catch (Exception $exception) {
            $this->logError($exception->getMessage(), 'MetricsProcessCountModal', [
                'server' => $serverName,
            ]);

            return '<div class="notification is-danger">Failed to render process count metrics.</div>';
        }
    }

    /**
     * Format metrics payload as JSON response.
     *
     * @param array<string,mixed> $series
     * @param array<string,mixed> $filters
     * @param array<string,mixed> $cache
     */
    public function renderMetricsJson(
        array $series,
        array $filters,
        array $cache = []
    ): JsonResponse {
        $payload = [
            'filters' => $filters,
            'series' => $series,
        ];

        if ($cache !== []) {
            $payload['cache'] = $cache;
        }

        return new JsonResponse($payload);
    }

    public function getMetricLabel(string $metricKey): string
    {
        return match ($metricKey) {
            'process_count' => 'Process Count',
            'cpu' => 'CPU Usage',
            'ram_percent' => 'RAM Usage (%)',
            'ram_used' => 'RAM Used',
            'disk_percent' => 'Disk Usage (%)',
            'disk_used' => 'Disk Used',
            'file_count' => 'File Count',
            default => ucwords(str_replace('_', ' ', $metricKey)),
        };
    }

    /**
     * Render validation error notification.
     *
     * @param array<string,string> $errors     Validation errors (field => message)
     * @param int                  $statusCode HTTP status code
     *
     * @return string Error notification HTML
     */
    public function renderValidationError(array $errors, int $statusCode = 422): string
    {
        $errorMessage = htmlspecialchars(implode('; ', $errors), ENT_QUOTES | ENT_HTML5);

        return sprintf(
            '<div class="notification is-danger">Invalid metrics filters: %s</div>',
            $errorMessage
        );
    }

    /**
     * Render generic error notification.
     *
     * @param string $message    Error message
     * @param int    $statusCode HTTP status code
     *
     * @return string Error notification HTML
     */
    public function renderError(string $message, int $statusCode = 422): string
    {
        $safeMessage = htmlspecialchars($message, ENT_QUOTES | ENT_HTML5);

        return sprintf(
            '<div class="notification is-danger">Unable to render metrics: %s</div>',
            $safeMessage
        );
    }

    /**
     * @return array<string,string>
     */
    private function getModalIcons(): array
    {
        return [
            'close' => $this->tools->icon('x-circle', 12),
            'refresh' => $this->tools->icon('refresh-cw', 12),
            'activity' => $this->tools->icon('activity', 12),
        ];
    }

    /**
     * @return array<string,string>
     */
    private function getDashboardIcons(): array
    {
        $icons = $this->getCommonIcons();

        return [
            'refresh' => $icons['refresh'],
            'activity' => $icons['activity'],
            'cpu' => $icons['cpu'],
            'hard_drive' => $icons['hard_drive'],
            'settings' => $icons['settings'],
        ];
    }
}
