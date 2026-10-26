<?php

declare(strict_types=1);

namespace Mata\Cli;

use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\OutputInterface;

/** The PHP processes of a node response as processes show and evidence print them. */
final class ProcessTable
{
    /**
     * The processes by CPU usage, busiest first.
     *
     * @return list<array<mixed>>
     */
    public static function busiestFirst(mixed $result): array
    {
        $processes = array_values(array_filter(is_array($result) ? $result : [], 'is_array'));
        usort($processes, static fn (array $a, array $b): int => (float) ($b['cpu'] ?? 0) <=> (float) ($a['cpu'] ?? 0));

        return $processes;
    }

    /** @param array<mixed> $processes from busiestFirst() */
    public static function write(OutputInterface $output, array $processes): void
    {
        // Compact: the command line can be long, and borders would stretch every row to it.
        $table = (new Table($output))->setStyle('compact');
        $table->setHeaders(['PID', 'User', '%CPU', '%MEM', 'Elapsed', 'Command']);
        CliOutput::alignRight($table, 0, 2, 3, 4);
        foreach ($processes as $process) {
            $table->addRow([
                CliOutput::value($process['pid'] ?? null),
                CliOutput::value($process['user'] ?? null),
                CliOutput::value($process['cpu'] ?? null),
                CliOutput::value($process['mem'] ?? null),
                CliOutput::value($process['etime'] ?? null),
                CliOutput::value($process['args'] ?? null),
            ]);
        }
        $table->render();
    }
}
