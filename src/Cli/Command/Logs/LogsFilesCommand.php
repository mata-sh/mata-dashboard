<?php

declare(strict_types=1);

namespace Mata\Cli\Command\Logs;

use Mata\Cli\Command\AbstractNodeEvidenceCommand;
use Mata\Services\Cache\CacheType;
use Mata\Services\Collect\ServerDataCollectorInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** Size, line count and last change of each log file of an application, e.g. to see whether a log stopped. */
#[AsCommand(name: 'logs:files', description: 'Show the size and last change of the log files of an application')]
final class LogsFilesCommand extends AbstractNodeEvidenceCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->addArgument('app', InputArgument::REQUIRED, 'Application name, see apps list');
    }

    protected function dataset(InputInterface $input): string
    {
        return 'log files';
    }

    protected function fetch(ServerDataCollectorInterface $collector, bool $live, InputInterface $input): ?array
    {
        return $collector->fetchAppLogsStatus(self::app($input), self::cacheType($live, CacheType::AppLogs));
    }

    protected function extract(mixed $result, InputInterface $input): array
    {
        $files = [];
        foreach (is_array($result) ? $result : [] as $path => $file) {
            $files[] = ['path' => (string) $path] + (is_array($file) ? $file : []);
        }

        return ['app' => (string) $input->getArgument('app'), 'files' => $files];
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
        $right = (clone Table::getStyleDefinition('default'))->setPadType(STR_PAD_LEFT);
        $table->setColumnStyle(1, $right);
        $table->setColumnStyle(2, $right);
        foreach ($files as $file) {
            $table->addRow([
                OutputFormatter::escape($file['path']),
                $file['size'] ?? '-',
                $file['lines'] ?? '-',
                $file['last_modified'] ?? '-',
            ]);
        }
        $table->render();
    }
}
