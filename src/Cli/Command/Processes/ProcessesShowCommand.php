<?php

declare(strict_types=1);

namespace Mata\Cli\Command\Processes;

use Mata\Cli\Command\AbstractNodeEvidenceCommand;
use Mata\Cli\Evidence;
use Mata\Services\Cache\CacheType;
use Mata\Services\Collect\ServerDataCollectorInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** The node's snapshot of PHP processes, busiest first. Other processes are not listed. */
#[AsCommand(name: 'processes:show', description: 'Show the PHP processes of a server, by CPU usage')]
final class ProcessesShowCommand extends AbstractNodeEvidenceCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Number of processes', '50');
    }

    protected function dataset(InputInterface $input): string
    {
        return 'php processes';
    }

    protected function fetch(ServerDataCollectorInterface $collector, bool $live, InputInterface $input): ?array
    {
        return $collector->getServerPhpProcesses(self::cacheType($live, CacheType::ServerProcesses));
    }

    protected function extract(mixed $result, InputInterface $input): array
    {
        $limit = Evidence::limit($input);
        $processes = array_values(array_filter(is_array($result) ? $result : [], 'is_array'));
        usort($processes, static fn (array $a, array $b): int => (float) ($b['cpu'] ?? 0) <=> (float) ($a['cpu'] ?? 0));

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

        // Compact: the command line can be long, and borders would stretch every row to it.
        $table = (new Table($output))->setStyle('compact');
        $table->setHeaders(['PID', 'USER', '%CPU', '%MEM', 'ELAPSED', 'COMMAND']);
        $right = (clone Table::getStyleDefinition('compact'))->setPadType(STR_PAD_LEFT);
        foreach ([0, 2, 3, 4] as $column) {
            $table->setColumnStyle($column, $right);
        }
        foreach ($processes as $process) {
            $table->addRow([
                $process['pid'] ?? '-',
                OutputFormatter::escape((string) ($process['user'] ?? '-')),
                $process['cpu'] ?? '-',
                $process['mem'] ?? '-',
                $process['etime'] ?? '-',
                OutputFormatter::escape((string) ($process['args'] ?? '')),
            ]);
        }
        $table->render();
    }
}
