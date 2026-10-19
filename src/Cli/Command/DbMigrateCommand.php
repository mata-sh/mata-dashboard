<?php

declare(strict_types=1);

namespace Mata\Cli\Command;

use Mata\Cli\CliOutput;
use Mata\Services\Status\StatusLevel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'db:migrate', description: 'Apply pending database migrations')]
final class DbMigrateCommand extends AbstractDbCommand
{
    protected function writes(): bool
    {
        return true;
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $migrations = $this->migrations($input, $output);
        $pending = $migrations->pending();
        $migrations->migrate();

        if ($input->getOption('json')) {
            CliOutput::json($output, ['status' => 'ok', 'applied' => $pending]);
        } else {
            $output->writeln('<info>Applied ' . count($pending) . ' migration(s).</info>');
        }

        return StatusLevel::Ok->value;
    }
}
