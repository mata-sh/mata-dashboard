<?php

declare(strict_types=1);

namespace Mata\Cli\Command;

use Mata\Cli\CliOutput;
use Mata\Services\ServerConfig\ServerConfigFileEditor;
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
    public function __construct(private readonly ServerConfigFileEditor $files)
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
                $output->writeln('<error>Pass --yes to remove without a terminal.</error>');

                return CliOutput::EXIT_ERROR;
            }
            $helper = $this->getHelper('question');
            if (!$helper instanceof QuestionHelper
                || !$helper->ask($input, $output, new ConfirmationQuestion("Remove server '{$name}' and its key? [y/N] ", false))) {
                return CliOutput::EXIT_ERROR;
            }
        }

        try {
            $found = $this->files->remove($name);
        } catch (RuntimeException $exception) {
            $output->writeln("<error>{$exception->getMessage()}</error>");

            return CliOutput::EXIT_ERROR;
        }
        $exitCode = $found ? CliOutput::EXIT_OK : CliOutput::EXIT_ERROR;

        if ($json) {
            CliOutput::json($output, ['status' => CliOutput::statusName($exitCode), 'server' => $name, 'removed' => $found]);
        } else {
            $output->writeln($found ? "<info>Removed '{$name}'.</info> Stored history stays in the database." : "<error>Server '{$name}' not found.</error>");
        }

        return $exitCode;
    }
}
