<?php

declare(strict_types=1);

namespace Mata\Services\Audit;

use Mata\Constants\Severities;

/**
 * Aggregates normalized Composer audit responses.
 */
final class AuditAggregator
{
    /**
     * @param array<string, array<string, null|array>> $serverAppAudits [server => [app => normalizedApiResponse|null]]
     *
     * @return array<string,mixed>
     */
    public function summarize(array $serverAppAudits): array
    {
        $totals = $this->emptyCounts();
        $summary = [
            'totals' => $totals,
            'affected_apps' => 0,
            'audited_apps' => 0,
            'error_apps' => 0,
            'never_audited_apps' => 0,
            'servers' => [],
        ];

        foreach ($serverAppAudits as $server => $appAudits) {
            $serverSummary = [
                'last_audited_age' => null,
                'stale' => false,
                'apps' => [],
            ];

            foreach ($appAudits as $app => $entry) {
                if ($entry === null) {
                    ++$summary['never_audited_apps'];
                    $serverSummary['apps'][$app] = [
                        'status' => 'none',
                        'counts' => $this->emptyCounts(),
                        'worst_severity' => null,
                        'cache_age' => null,
                    ];

                    continue;
                }

                $result = is_array($entry['result'] ?? null) ? $entry['result'] : [];
                $hasError = array_key_exists('error', $entry) && $entry['error'] !== null;
                $isOk = !$hasError && ($result['status'] ?? null) === 'ok';
                $counts = $this->normalizeCounts($result['counts'] ?? []);
                $cacheAge = is_int($entry['cache_age'] ?? null) ? max(0, $entry['cache_age']) : null;

                if (!$isOk) {
                    ++$summary['error_apps'];
                    $serverSummary['apps'][$app] = [
                        'status' => 'error',
                        'counts' => $counts,
                        'worst_severity' => $this->worstSeverity($counts),
                        'cache_age' => $cacheAge,
                    ];

                    continue;
                }

                ++$summary['audited_apps'];
                if ($counts['total'] > 0) {
                    ++$summary['affected_apps'];
                }
                foreach ($totals as $severity => $_) {
                    $totals[$severity] += $counts[$severity];
                }

                if ($cacheAge !== null) {
                    $serverSummary['last_audited_age'] = $serverSummary['last_audited_age'] === null
                        ? $cacheAge
                        : min($serverSummary['last_audited_age'], $cacheAge);
                }
                $serverSummary['stale'] = $serverSummary['stale'] || ($entry['stale_cache'] ?? false) === true;
                $serverSummary['apps'][$app] = [
                    'status' => 'ok',
                    'counts' => $counts,
                    'worst_severity' => $this->worstSeverity($counts),
                    'cache_age' => $cacheAge,
                ];
            }

            $summary['servers'][$server] = $serverSummary;
        }

        $summary['totals'] = $totals;

        return $summary;
    }

    /**
     * @param array<string,int> $counts
     *
     * @return null|'critical'|'high'|'low'|'medium'|'unknown'
     */
    public function worstSeverity(array $counts): ?string
    {
        if (($counts['total'] ?? 0) <= 0) {
            return null;
        }

        foreach (Severities::ORDER as $severity) {
            if (($counts[$severity] ?? 0) > 0) {
                return $severity;
            }
        }

        return 'unknown';
    }

    /**
     * @param array $normalizedAudit the canonical per-app audit shape
     *
     * @return array<string,array{worst_severity:string,count:int,advisories:array}>
     */
    public function advisoriesByPackage(array $normalizedAudit): array
    {
        $groups = [];
        foreach ($normalizedAudit['advisories'] ?? [] as $advisory) {
            if (!is_array($advisory) || !is_string($advisory['package'] ?? null) || $advisory['package'] === '') {
                continue;
            }

            $package = $advisory['package'];
            $severity = $advisory['severity'] ?? 'unknown';
            if (!is_string($severity) || !in_array($severity, Severities::ORDER, true)) {
                $severity = 'unknown';
            }

            if (!isset($groups[$package])) {
                $groups[$package] = [
                    'severity_counts' => $this->emptyCounts(),
                    'advisories' => [],
                ];
            }

            ++$groups[$package]['severity_counts'][$severity];
            ++$groups[$package]['severity_counts']['total'];
            $groups[$package]['advisories'][] = $advisory;
        }

        $result = [];
        foreach ($groups as $package => $group) {
            $result[$package] = [
                'worst_severity' => $this->worstSeverity($group['severity_counts']) ?? 'unknown',
                'count' => $group['severity_counts']['total'],
                'advisories' => $group['advisories'],
            ];
        }

        return $result;
    }

    /** @return array{critical:int,high:int,medium:int,low:int,unknown:int,total:int} */
    private function emptyCounts(): array
    {
        return array_fill_keys(Severities::ORDER, 0) + ['total' => 0];
    }

    private function normalizeCounts(mixed $counts): array
    {
        $normalized = $this->emptyCounts();
        if (!is_array($counts)) {
            return $normalized;
        }

        foreach ($normalized as $severity => $_) {
            $value = $counts[$severity] ?? 0;
            $normalized[$severity] = is_int($value) ? max(0, $value) : 0;
        }

        return $normalized;
    }
}
