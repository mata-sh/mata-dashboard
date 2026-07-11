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
     * @return array{audited:bool,packages:array<string,array{worst_severity:string,count:int,advisories:array}>}
     */
    public function appAudit(ServerDataCollectorInterface $collector, string $app): array
    {
        $auditResponse = $collector->getCachedAppComposerAudit($app);
        $packages = [];
        $audited = false;
        if ($auditResponse !== null && is_array($auditResponse['result'] ?? null)) {
            $packages = $this->auditAggregator->advisoriesByPackage($auditResponse['result']);
            $audited = ($auditResponse['result']['status'] ?? null) === 'ok';
        }

        return ['audited' => $audited, 'packages' => $packages];
    }

    /**
     * Stamp audit fields onto one library row.
     *
     * @param array<string,mixed>                                                                                $row
     * @param array{audited:bool,packages:array<string,array{worst_severity:string,count:int,advisories:array}>} $appAudit
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

        $packageAudit = $appAudit['packages'][$row['name'] ?? ''] ?? null;
        if (!is_array($packageAudit)) {
            return $row;
        }

        $row['vulns'] = (int) ($packageAudit['count'] ?? 0);

        if ($row['vulns'] > 0) {
            $row['severity'] = $packageAudit['worst_severity'] ?? null;
        }

        $advisories = $packageAudit['advisories'] ?? [];
        if (is_array($advisories) && $advisories !== []) {
            $row['advisories'] = $advisories;
        }

        return $row;
    }
}
