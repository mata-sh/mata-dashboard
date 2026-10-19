<?php

declare(strict_types=1);

namespace Mata\Cli\Command;

use Mata\Cli\CliOutput;
use Mata\Services\Status\StatusLevel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'db:rollback', description: 'Roll back the last database migration')]
final class DbRollbackCommand extends AbstractDbCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setHelp('Rolls back one migration. Back up first: make db-backup.');
    }

    protected function writes(): bool
    {
        return true;
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $this->migrations($input, $output)->rollback();

        if ($input->getOption('json')) {
            CliOutput::json($output, ['status' => 'ok']);
        } else {
            $output->writeln('<info>Rolled back the last migration.</info>');
        }

        return StatusLevel::Ok->value;
    }
}
