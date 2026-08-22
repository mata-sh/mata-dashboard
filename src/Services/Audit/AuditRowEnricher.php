<?php

declare(strict_types=1);

namespace Mata\Services\Audit;

use Mata\Services\Collect\ServerDataCollectorInterface;

final class AuditRowEnricher
{
    public function __construct(private readonly AuditAggregator $auditAggregator) {}

    /**
     * Look up the cached audit for one app.
     *
     * @return array{audited:bool,packages:array<string,array{worst_severity:string,count:int,advisories:list<array<string,mixed>>}>,abandoned:array<string,null|string>}
     */
    public function appAudit(ServerDataCollectorInterface $collector, string $app): array
    {
        $auditResponse = $collector->getCachedAppComposerAudit($app);
        $packages = [];
        $abandoned = [];
        $audited = false;
        if ($auditResponse !== null && is_array($auditResponse['result'] ?? null)) {
            $result = $auditResponse['result'];
            $audited = ($result['status'] ?? null) === 'ok';
            if ($audited) {
                $packages = $this->auditAggregator->advisoriesByPackage($result);
                $abandoned = $this->normalizeAbandoned($result['abandoned'] ?? []);
            }
        }

        return ['audited' => $audited, 'packages' => $packages, 'abandoned' => $abandoned];
    }

    /**
     * Stamp audit fields onto one library row.
     *
     * @param array<string,mixed>                                                                                                                                        $row
     * @param array{audited:bool,packages:array<string,array{worst_severity:string,count:int,advisories:list<array<string,mixed>>}>,abandoned:array<string,null|string>} $appAudit
     *
     * @return array<string,mixed>
     */
    public function enrichRow(array $row, array $appAudit): array
    {
        $row['vulns'] = 0;
        $row['severity'] = null;

        if (!($appAudit['audited'] ?? false)) {
            return $row;
        }

        $name = $row['name'] ?? null;
        $packageAudit = is_string($name) ? ($appAudit['packages'][$name] ?? null) : null;
        if (is_array($packageAudit)) {
            $row['vulns'] = (int) ($packageAudit['count'] ?? 0);

            if ($row['vulns'] > 0) {
                $row['severity'] = $packageAudit['worst_severity'] ?? null;
            }

            $advisories = $packageAudit['advisories'] ?? [];
            if (is_array($advisories) && $advisories !== []) {
                $row['advisories'] = $advisories;
            }
        }

        if (is_string($name) && array_key_exists($name, $appAudit['abandoned'] ?? [])) {
            $replacement = $appAudit['abandoned'][$name];
            $row['abandoned'] = is_string($replacement) && $replacement !== '' ? $replacement : true;
        }

        return $row;
    }

    /** @return array<string,null|string> */
    private function normalizeAbandoned(mixed $abandoned): array
    {
        if (!is_array($abandoned)) {
            return [];
        }

        $normalized = [];
        foreach ($abandoned as $package => $replacement) {
            if (!is_string($package) || $package === '') {
                continue;
            }

            $normalized[$package] = is_string($replacement) && $replacement !== '' ? $replacement : null;
        }

        return $normalized;
    }
}
