<?php

declare(strict_types=1);

namespace Mata\Cli\Command;

use Mata\Cli\CliOutput;
use Mata\Database\PdoDatabase;
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
        $rows = (new PdoDatabase($this->config->database))->runSelect(<<<'SQL'
            SELECT 'raw' AS tier, COUNT(*) AS row_count, MAX(collected_at) AS latest FROM server_metrics_raw
            UNION ALL
            SELECT 'hourly' AS tier, COUNT(*) AS row_count, MAX(hour_timestamp) AS latest FROM server_metrics_hourly
            UNION ALL
            SELECT 'daily' AS tier, COUNT(*) AS row_count, MAX(date) AS latest FROM server_metrics_daily
            SQL);

        $tiers = array_map(static fn (array $row): array => [
            'tier' => (string) $row['tier'],
            'rows' => (int) $row['row_count'],
            'latest' => $row['latest'] !== null ? (string) $row['latest'] : null,
        ], $rows);

        if ($input->getOption('json')) {
            CliOutput::json($output, ['status' => StatusLevel::Ok->label(), 'tiers' => $tiers]);

            return StatusLevel::Ok->value;
        }
        foreach ($tiers as $tier) {
            $output->writeln(sprintf('%-6s rows=%d latest=%s', $tier['tier'], $tier['rows'], $tier['latest'] ?? 'none'));
        }

        return StatusLevel::Ok->value;
    }
}
