<?php

declare(strict_types=1);

namespace Mata\Cli\Command;

use Mata\Cli\CliOutput;
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

        return CliOutput::ok($input, $output, 'Applied ' . count($pending) . ' migration(s).', ['applied' => $pending]);
    }
}
