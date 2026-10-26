<?php

declare(strict_types=1);

namespace Mata\Cli\Command\Metrics;

use DateTimeImmutable;
use Mata\Cli\Command\AbstractResultCommand;
use Mata\Cli\Provenance;
use Mata\Database\PdoDatabase;
use Mata\Models\ServerMetrics\ServerMetricsRepository;
use Mata\Models\Sync\ConfigSyncRepository;
use Mata\Services\Identity\ServerApplicationIdResolver;
use Mata\Services\Status\StatusLevel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Row count and latest timestamp of each metrics tier: a stale raw tier means collection stopped,
 * a stale hourly or daily tier means aggregation did.
 */
#[AsCommand(name: 'metrics:status', description: 'Show row counts and the latest timestamp of each metrics tier')]
final class MetricsStatusCommand extends AbstractResultCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $db = new PdoDatabase($this->cli->load()->database);
        $metrics = new ServerMetricsRepository($db, new ServerApplicationIdResolver(), new ConfigSyncRepository($db));
        $tiers = $metrics->getTierSummary();
        $data = Provenance::fromDatabase('server_metrics_*', new DateTimeImmutable()) + ['tiers' => $tiers];

        return $this->report($input, $output, StatusLevel::Ok, $data, function () use ($output, $tiers): void {
            foreach ($tiers as $tier) {
                $output->writeln(sprintf('%-6s rows=%d latest=%s', $tier['tier'], $tier['rows'], $tier['latest'] ?? 'none'));
            }
        });
    }
}
