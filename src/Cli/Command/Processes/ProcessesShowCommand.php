<?php

declare(strict_types=1);

namespace Mata\Cli\Command\Processes;

use Mata\Cli\CliInput;
use Mata\Cli\CliOutput;
use Mata\Cli\Command\AbstractNodeDatasetCommand;
use Mata\Cli\ProcessTable;
use Mata\Services\Cache\CacheType;
use Mata\Services\Collect\ServerDataCollectorInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** The node's snapshot of PHP processes, busiest first. Other processes are not listed. */
#[AsCommand(name: 'processes:show', description: 'Show the PHP processes of a server, by CPU usage')]
final class ProcessesShowCommand extends AbstractNodeDatasetCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Number of processes', '50');
    }

    protected function datasetName(InputInterface $input): string
    {
        return 'php processes';
    }

    protected function fetch(ServerDataCollectorInterface $collector, bool $live, InputInterface $input): ?array
    {
        return $collector->getServerPhpProcesses(self::cacheType($live, CacheType::ServerProcesses));
    }

    protected function extract(mixed $result, InputInterface $input): array
    {
        $limit = CliInput::limit($input);
        $processes = ProcessTable::busiestFirst($result);

        return [
            'matched' => count($processes),
            'truncated' => count($processes) > $limit,
            'processes' => array_slice($processes, 0, $limit),
        ];
    }

    protected function writeHuman(OutputInterface $output, array $data): void
    {
        $processes = is_array($data['processes']) ? $data['processes'] : [];
        if ($processes === []) {
            $output->writeln('No PHP processes.');

            return;
        }

        ProcessTable::write($output, $processes);
        if ($data['truncated'] === true) {
            CliOutput::truncated($output, 'processes', count($processes), (int) $data['matched']);
        }
    }
}
