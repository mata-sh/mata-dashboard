<?php

declare(strict_types=1);

namespace Mata\Cli\Command;

use Mata\Cli\CliOutput;
use Mata\Database\DatabaseIntegrityChecker;
use Mata\Database\PdoDatabase;
use Mata\Services\Status\StatusLevel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'db:check', description: 'Run the database integrity checks')]
final class DbCheckCommand extends AbstractDbCommand
{
    protected function writes(): bool
    {
        return false;
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $result = (new DatabaseIntegrityChecker(new PdoDatabase($this->config->database)))->run();
        $level = $result['status'] === 'ok' ? StatusLevel::Ok : StatusLevel::Error;

        if ($input->getOption('json')) {
            CliOutput::json($output, ['status' => $level->label(), 'checks' => $result['checks']]);

            return $level->value;
        }
        foreach ($result['checks'] as $check) {
            $details = ($check['details'] ?? '') !== '' ? " ({$check['details']})" : '';
            $output->writeln('  ' . CliOutput::mark($check['status'] === 'ok' ? StatusLevel::Ok : StatusLevel::Error) . " {$check['label']}{$details}");
        }
        $output->writeln($level === StatusLevel::Ok ? '<info>Integrity checks passed.</info>' : '<error>Integrity checks failed.</error>');

        return $level->value;
    }
}
