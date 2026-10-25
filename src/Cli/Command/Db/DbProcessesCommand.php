<?php

declare(strict_types=1);

namespace Mata\Cli\Command\Db;

use Mata\Cli\Command\AbstractNodeEvidenceCommand;
use Mata\Services\Collect\ServerDataCollectorInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The live process list of one monitored database connection. Never cached, so it always asks
 * the node. `visibility` says whether the node's database user sees all sessions or only its own.
 */
#[AsCommand(name: 'db:processes', description: 'Show the live process list of a monitored database connection')]
final class DbProcessesCommand extends AbstractNodeEvidenceCommand
{
    protected function alwaysLive(): bool
    {
        return true;
    }

    protected function configure(): void
    {
        parent::configure();
        $this
            ->addArgument('connection', InputArgument::REQUIRED, 'Connection name, see db show')
            ->addOption('database', null, InputOption::VALUE_REQUIRED, 'Only sessions of this monitored database');
    }

    protected function dataset(InputInterface $input): string
    {
        return 'database processes';
    }

    protected function fetch(ServerDataCollectorInterface $collector, bool $live, InputInterface $input): ?array
    {
        $database = $input->getOption('database');

        return $collector->fetchDatabaseProcesses((string) $input->getArgument('connection'), is_string($database) ? $database : null);
    }

    protected function extract(mixed $result, InputInterface $input): array
    {
        return is_array($result) ? $result : [];
    }

    protected function writeHuman(OutputInterface $output, array $data): void
    {
        $output->writeln(OutputFormatter::escape(sprintf(
            '%s (%s), visibility: %s',
            $data['connection'] ?? '-',
            $data['engine'] ?? '-',
            $data['visibility'] ?? '-',
        )));

        $summary = is_array($data['summary_by_database'] ?? null) ? $data['summary_by_database'] : [];
        if ($summary !== []) {
            $table = new Table($output);
            $table->setHeaders(['Database', 'Connections', 'Running']);
            $right = (clone Table::getStyleDefinition('default'))->setPadType(STR_PAD_LEFT);
            $table->setColumnStyle(1, $right);
            $table->setColumnStyle(2, $right);
            foreach ($summary as $group) {
                $table->addRow([OutputFormatter::escape((string) $group['database']), $group['connections'], $group['running']]);
            }
            $table->render();
        }

        $processes = is_array($data['processes'] ?? null) ? $data['processes'] : [];
        if ($processes === []) {
            $output->writeln('No sessions.');

            return;
        }

        // Compact: statements can be long, and borders would stretch every row to them.
        $table = (new Table($output))->setStyle('compact');
        $table->setHeaders(['ID', 'USER', 'DATABASE', 'COMMAND', 'TIME', 'STATEMENT']);
        $right = (clone Table::getStyleDefinition('compact'))->setPadType(STR_PAD_LEFT);
        $table->setColumnStyle(0, $right);
        $table->setColumnStyle(4, $right);
        foreach ($processes as $process) {
            $table->addRow([
                $process['id'] ?? '-',
                OutputFormatter::escape((string) ($process['user'] ?? '-')),
                OutputFormatter::escape((string) ($process['database'] ?? '-')),
                OutputFormatter::escape((string) ($process['command'] ?? '-')),
                isset($process['time_seconds']) ? "{$process['time_seconds']}s" : '-',
                OutputFormatter::escape((string) ($process['statement'] ?? $process['state'] ?? '')),
            ]);
        }
        $table->render();
        if (($data['truncated'] ?? false) === true) {
            $output->writeln('The node truncated the list.');
        }
    }
}
