<?php

declare(strict_types=1);

namespace Mata\Cli\Command\Migrate;

use Mata\Cli\CliOutput;
use Mata\Services\Status\StatusLevel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'migrate:status', description: 'Show pending database migrations')]
final class MigrateStatusCommand extends AbstractMigrateCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setHelp('Exit codes: 0 up to date, 1 migrations pending, 2 database unreachable or config broken.');
    }

    protected function writes(): bool
    {
        return false;
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $migrations = $this->migrations($input, $output);
        $pending = $migrations->pending();
        $level = $pending === [] ? StatusLevel::Ok : StatusLevel::Warning;

        if ($input->getOption('json')) {
            CliOutput::json($output, ['status' => $level->label(), 'environment' => $this->config->environment->env, 'pending' => $pending]);
        } else {
            $migrations->printStatus();
            $output->writeln($pending === [] ? '<info>Up to date.</info>' : '<comment>' . count($pending) . ' pending; run bin/mata migrate up</comment>');
        }

        return $level->value;
    }
}
