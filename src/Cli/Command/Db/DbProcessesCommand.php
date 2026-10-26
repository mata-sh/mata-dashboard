<?php

declare(strict_types=1);

namespace Mata\Cli\Command\Db;

use Mata\Cli\CliInput;
use Mata\Cli\CliOutput;
use Mata\Cli\Command\AbstractNodeDatasetCommand;
use Mata\Services\Collect\ServerDataCollectorInterface;
use Symfony\Component\Console\Attribute\AsCommand;
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
final class DbProcessesCommand extends AbstractNodeDatasetCommand
{
    protected function alwaysAsksNode(): bool
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

    protected function datasetName(InputInterface $input): string
    {
        return 'database processes';
    }

    protected function fetch(ServerDataCollectorInterface $collector, bool $live, InputInterface $input): ?array
    {
        $connection = (string) $input->getArgument('connection');

        return $collector->fetchDatabaseProcesses($connection, CliInput::stringOption($input, 'database'));
    }

    protected function extract(mixed $result, InputInterface $input): array
    {
        return is_array($result) ? $result : [];
    }

    protected function writeHuman(OutputInterface $output, array $data): void
    {
        $output->writeln(sprintf(
            '%s (%s), visibility: %s',
            CliOutput::value($data['connection'] ?? null),
            CliOutput::value($data['engine'] ?? null),
            CliOutput::value($data['visibility'] ?? null),
        ));

        $summary = is_array($data['summary_by_database'] ?? null) ? $data['summary_by_database'] : [];
        if ($summary !== []) {
            $table = new Table($output);
            $table->setHeaders(['Database', 'Connections', 'Running']);
            CliOutput::alignRight($table, 1, 2);
            foreach ($summary as $group) {
                $table->addRow([
                    CliOutput::value($group['database'] ?? null),
                    CliOutput::value($group['connections'] ?? null),
                    CliOutput::value($group['running'] ?? null),
                ]);
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
        $table->setHeaders(['ID', 'User', 'Database', 'Command', 'Time', 'Statement']);
        CliOutput::alignRight($table, 0, 4);
        foreach ($processes as $process) {
            $table->addRow([
                CliOutput::value($process['id'] ?? null),
                CliOutput::value($process['user'] ?? null),
                CliOutput::value($process['database'] ?? null),
                CliOutput::value($process['command'] ?? null),
                isset($process['time_seconds']) ? CliOutput::value($process['time_seconds']) . 's' : '-',
                CliOutput::value($process['statement'] ?? $process['state'] ?? null),
            ]);
        }
        $table->render();
        // The node caps the list itself; --limit does not apply here.
        if (($data['truncated'] ?? false) === true) {
            $output->writeln('<comment>The node returned only part of the sessions.</comment>');
        }
    }
}
