<?php

declare(strict_types=1);

namespace Mata\Cli\Command;

use Mata\Cli\CliOutput;
use Mata\Database\DatabaseIntegrityChecker;
use Mata\Database\PdoDatabase;
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
        $exitCode = $result['status'] === 'ok' ? CliOutput::EXIT_OK : CliOutput::EXIT_ERROR;

        if ($input->getOption('json')) {
            CliOutput::json($output, ['status' => CliOutput::statusName($exitCode), 'checks' => $result['checks']]);

            return $exitCode;
        }
        foreach ($result['checks'] as $check) {
            $details = ($check['details'] ?? '') !== '' ? " ({$check['details']})" : '';
            $output->writeln(($check['status'] === 'ok' ? '  <info>✓</info> ' : '  <error>✗</error> ') . $check['label'] . $details);
        }
        $output->writeln($exitCode === CliOutput::EXIT_OK ? '<info>Integrity checks passed.</info>' : '<error>Integrity checks failed.</error>');

        return $exitCode;
    }
}
