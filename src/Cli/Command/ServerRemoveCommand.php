<?php

declare(strict_types=1);

namespace Mata\Cli\Command;

use Mata\Cli\CliOutput;
use Mata\Config\Monitoring\ServersConfigEditor;
use Mata\Services\Status\StatusLevel;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

#[AsCommand(name: 'server:remove', description: 'Remove a server from servers.json and its key from servers.secrets.json')]
final class ServerRemoveCommand extends Command
{
    public function __construct(private readonly ServersConfigEditor $files)
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

        if (!$input->getOption('yes')) {
            if (!$input->isInteractive()) {
                return CliOutput::fail($input, $output, 'Pass --yes to remove without a terminal.');
            }
            $helper = $this->getHelper('question');
            if (!$helper instanceof QuestionHelper
                || !$helper->ask($input, $output, new ConfirmationQuestion("Remove server '{$name}' and its key? [y/N] ", false))) {
                return CliOutput::fail($input, $output, 'Cancelled.');
            }
        }

        try {
            $found = $this->files->remove($name);
        } catch (RuntimeException $exception) {
            return CliOutput::fail($input, $output, $exception->getMessage());
        }
        if (!$found) {
            return CliOutput::fail($input, $output, "Server '{$name}' not found.", payload: ['server' => $name, 'removed' => false]);
        }

        if ($json) {
            CliOutput::json($output, ['status' => 'ok', 'server' => $name, 'removed' => true]);
        } else {
            $output->writeln("<info>Removed '{$name}'.</info> Stored history stays in the database.");
        }

        return StatusLevel::Ok->value;
    }
}
