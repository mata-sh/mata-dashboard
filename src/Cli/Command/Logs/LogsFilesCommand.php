<?php

declare(strict_types=1);

namespace Mata\Cli\Command\Logs;

use Mata\Cli\CliOutput;
use Mata\Cli\Command\AbstractNodeDatasetCommand;
use Mata\Services\Cache\CacheType;
use Mata\Services\Collect\ServerDataCollectorInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** Size, line count and last change of each log file of an application, e.g. to see whether a log stopped. */
#[AsCommand(name: 'logs:files', description: 'Show the size and last change of the log files of an application')]
final class LogsFilesCommand extends AbstractNodeDatasetCommand
{
    protected function requiresApp(): bool
    {
        return true;
    }

    protected function datasetName(InputInterface $input): string
    {
        return 'log files';
    }

    protected function fetch(ServerDataCollectorInterface $collector, bool $live, InputInterface $input): ?array
    {
        return $collector->fetchAppLogsStatus(self::appArgument($input), self::cacheType($live, CacheType::AppLogs));
    }

    protected function extract(mixed $result, InputInterface $input): array
    {
        $files = [];
        foreach (is_array($result) ? $result : [] as $path => $file) {
            $files[] = ['path' => (string) $path] + (is_array($file) ? $file : []);
        }

        return ['files' => $files];
    }

    protected function writeHuman(OutputInterface $output, array $data): void
    {
        $files = is_array($data['files']) ? $data['files'] : [];
        if ($files === []) {
            $output->writeln('No log files.');

            return;
        }

        $table = new Table($output);
        $table->setHeaders(['Path', 'Bytes', 'Lines', 'Last modified']);
        CliOutput::alignRight($table, 1, 2);
        foreach ($files as $file) {
            $table->addRow([
                CliOutput::value($file['path']),
                CliOutput::value($file['size'] ?? null),
                CliOutput::value($file['lines'] ?? null),
                CliOutput::value($file['last_modified'] ?? null),
            ]);
        }
        $table->render();
    }
}
