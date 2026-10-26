<?php

declare(strict_types=1);

namespace Mata\Cli\Command\Migrate;

use Mata\Cli\CliOutput;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'migrate:up', description: 'Apply pending database migrations')]
final class MigrateUpCommand extends AbstractMigrateCommand
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

        return CliOutput::ok($input, $output, 'Applied ' . count($pending) . ' migration(s).', ['applied' => $pending]);
    }
}
