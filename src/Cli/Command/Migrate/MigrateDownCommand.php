<?php

declare(strict_types=1);

namespace Mata\Cli\Command\Migrate;

use Mata\Cli\CliOutput;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'migrate:down', description: 'Roll back the last database migration')]
final class MigrateDownCommand extends AbstractMigrateCommand
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

        return CliOutput::ok($input, $output, 'Rolled back the last migration.');
    }
}
