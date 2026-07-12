<?php

declare(strict_types=1);

namespace Mata\Services\Collect;

use InvalidArgumentException;

/** Selects one server metric to display at alarm level in a demo dashboard. */
final class DemoAlarmMetricScenario
{
    private string $server;
    private string $metric;

    /**
     * @param array<int,string> $servers
     */
    public function __construct(array $servers)
    {
        $servers = array_values(array_filter($servers, static fn (mixed $server): bool => is_string($server) && $server !== ''));
        if ($servers === []) {
            throw new InvalidArgumentException('A demo alarm scenario requires at least one server.');
        }

        $this->server = $servers[array_rand($servers)];
        $metrics = ['cpu', 'ram', 'disk', 'filecount', 'cronjobs'];
        $this->metric = $metrics[array_rand($metrics)];
    }

    public function metricFor(string $server): ?string
    {
        return $server === $this->server ? $this->metric : null;
    }
}
