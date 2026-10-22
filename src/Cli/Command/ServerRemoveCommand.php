<?php

declare(strict_types=1);

namespace Mata\Cli\Command;

use Mata\Cli\CliOutput;
use Mata\Config\Monitoring\ServersConfigEditor;
use Mata\Services\Status\StatusLevel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'server:remove', description: 'Remove a server from servers.json and its key from servers.secrets.json')]
final class ServerRemoveCommand extends Command
{
    public function __construct(private readonly ServersConfigEditor $editor)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Server to remove')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Do not ask for confirmation (required without a terminal)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print as JSON')
            ->setHelp('Stored metrics, events and alarms stay in the database. Run as the owner of config/.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = (string) $input->getArgument('name');
        $json = (bool) $input->getOption('json');

        $refused = CliOutput::confirm($input, $output, "Remove server '{$name}' and its key?");
        if ($refused !== null) {
            return $refused;
        }

        if (!$this->editor->remove($name)) {
            return CliOutput::fail($input, $output, "Server '{$name}' not found.", payload: ['server' => $name, 'removed' => false]);
        }

        $warnings = $this->editor->warnings();
        $level = $warnings === [] ? StatusLevel::Ok : StatusLevel::Warning;
        if ($json) {
            CliOutput::json($output, ['status' => $level->label(), 'server' => $name, 'removed' => true, 'warnings' => $warnings]);
        } else {
            $output->writeln("<info>Removed '{$name}'.</info> Stored history stays in the database.");
            foreach ($warnings as $warning) {
                $output->writeln("<comment>{$warning}</comment>");
            }
        }

        return $level->value;
    }
}
