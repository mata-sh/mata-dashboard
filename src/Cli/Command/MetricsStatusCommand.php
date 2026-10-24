<?php

declare(strict_types=1);

namespace Mata\Cli\Command;

use Mata\Cli\CliOutput;
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
final class MetricsStatusCommand extends AbstractDbCommand
{
    protected function writes(): bool
    {
        return false;
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $db = new PdoDatabase($this->config->database);
        $metrics = new ServerMetricsRepository($db, new ServerApplicationIdResolver(), new ConfigSyncRepository($db));
        $tiers = $metrics->getTierSummary();

        if ($input->getOption('json')) {
            CliOutput::json($output, ['status' => StatusLevel::Ok->label(), 'tiers' => $tiers]);
        } else {
            foreach ($tiers as $tier) {
                $output->writeln(sprintf('%-6s rows=%d latest=%s', $tier['tier'], $tier['rows'], $tier['latest'] ?? 'none'));
            }
        }

        return StatusLevel::Ok->value;
    }
}
