<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring\Section;

use Mata\Services\ServerMetrics\MetricCatalog;
use Mata\Services\ServerMetrics\MetricQueryPolicy;

/** Typed chart-surface configuration. */
final readonly class ChartsConfig
{
    private function __construct(public ?DashboardChartConfig $dashboard) {}

    public static function empty(): self
    {
        return new self(null);
    }

    public static function default(MetricQueryPolicy $queryPolicy): self
    {
        return new self(DashboardChartConfig::default($queryPolicy));
    }

    /**
     * @param array<string,mixed>   $raw
     * @param callable(string):void $reportInvalid
     */
    public static function fromArray(
        array $raw,
        MetricCatalog $catalog,
        MetricQueryPolicy $queryPolicy,
        callable $reportInvalid,
    ): self {
        $dashboard = null;
        foreach ($raw as $surface => $surfaceConfig) {
            $surface = (string) $surface;
            if ($surface === '_comment') {
                continue;
            }
            if ($surface !== 'dashboard') {
                $reportInvalid("charts.json references unknown chart surface '{$surface}' -> ignored");

                continue;
            }
            if (!is_array($surfaceConfig)) {
                $reportInvalid("charts.json chart surface '{$surface}' must be an object -> ignored");

                continue;
            }

            $dashboard = DashboardChartConfig::fromArray(
                $surfaceConfig,
                $catalog,
                $queryPolicy,
                $reportInvalid,
            );
        }

        return new self($dashboard);
    }

    public function dashboardOrDefault(MetricQueryPolicy $queryPolicy): DashboardChartConfig
    {
        return $this->dashboard ?? DashboardChartConfig::default($queryPolicy);
    }
}
