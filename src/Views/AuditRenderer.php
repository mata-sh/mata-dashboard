<?php

declare(strict_types=1);

namespace Mata\Views;

/**
 * Renders Composer audit section fragments.
 */
class AuditRenderer extends BaseRenderer
{
    /**
     * @param array<string,mixed>       $summary
     * @param array<string,null|string> $endpoints
     */
    public function renderSection(array $summary, array $endpoints): string
    {
        return $this->twig->render('partials/containers/audit-section.html.twig', [
            'summary' => $this->prepareSummary($summary),
            'endpoints' => $endpoints,
        ]);
    }

    /** @param array<string,mixed> $summary */
    public function renderSummary(array $summary): string
    {
        return $this->twig->render('partials/audit/audit-summary.html.twig', [
            'summary' => $this->prepareSummary($summary),
        ]);
    }

    /** @param array<int,array{server:string,app:string,url:null|string}> $targets */
    public function renderRunPlaceholders(array $targets): string
    {
        return $this->twig->render('partials/audit/audit-run-placeholders.html.twig', [
            'targets' => $targets,
        ]);
    }

    /** @param null|array<string,mixed> $response */
    public function renderChipResult(string $server, string $app, ?array $response): string
    {
        $error = is_array($response['error'] ?? null) ? $response['error'] : null;
        $result = is_array($response['result'] ?? null) ? $response['result'] : null;
        $message = is_string($error['message'] ?? null)
            ? $error['message']
            : (is_string($result['message'] ?? null) ? $result['message'] : null);
        $isError = $response === null || $error !== null || ($result['status'] ?? 'error') !== 'ok';
        $counts = is_array($result['counts'] ?? null) ? $result['counts'] : [];
        $total = max(0, (int) ($counts['total'] ?? 0));

        return $this->twig->render('partials/audit/audit-chip-result.html.twig', [
            'server' => $server,
            'app' => $app,
            'error' => $isError,
            'message' => $message ?? 'node unreachable',
            'total' => $total,
            'counts' => $counts,
            'worst_severity' => $isError ? null : $this->worstSeverity($counts),
        ]);
    }

    /** @param array<string,mixed> $summary
     * @return array<string,mixed>
     */
    private function prepareSummary(array $summary): array
    {
        $servers = is_array($summary['servers'] ?? null) ? $summary['servers'] : [];
        foreach ($servers as $server => $details) {
            if (!is_array($details)) {
                continue;
            }
            $age = $details['last_audited_age'] ?? null;
            $details['last_audited_label'] = is_int($age) ? $this->tools->formatDuration($age) : null;
            $servers[$server] = $details;
        }
        $summary['servers'] = $servers;

        return $summary;
    }

    /** @param array<string,mixed> $counts */
    private function worstSeverity(array $counts): ?string
    {
        foreach (['critical', 'high', 'medium', 'low', 'unknown'] as $severity) {
            if ((int) ($counts[$severity] ?? 0) > 0) {
                return $severity;
            }
        }

        return null;
    }
}
