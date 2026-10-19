<?php

declare(strict_types=1);

namespace Mata\Cli\Command;

use Mata\Cli\CliOutput;
use Mata\Services\Auth\UserAdministrator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

#[AsCommand(name: 'user:delete', description: 'Delete a dashboard user')]
final class UserDeleteCommand extends AbstractUserCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->addArgument('username', InputArgument::REQUIRED);
        $this->setHelp('Asks for confirmation in a terminal; pass --yes otherwise.');
    }

    protected function writes(): bool
    {
        return true;
    }

    protected function handle(UserAdministrator $users, InputInterface $input, OutputInterface $output): int
    {
        $username = (string) $input->getArgument('username');
        if (!$input->getOption('yes') && $this->env !== 'prod') {
            // In prod the typed confirmation already ran.
            if (!$input->isInteractive()) {
                return CliOutput::fail($input, $output, 'Pass --yes to delete without a terminal.');
            }
            $helper = $this->getHelper('question');
            $confirmed = $helper instanceof QuestionHelper
                && $helper->ask($input, $output, new ConfirmationQuestion("Delete user '{$username}'? [y/N] ", false));
            if (!$confirmed) {
                return CliOutput::fail($input, $output, 'Cancelled.');
            }
        }

        $users->deleteUser($username);
        $this->audit->warning('User deleted', ['target_user' => $username]);

        return $this->succeed($input, $output, "User deleted: {$username}", ['username' => $username]);
    }
}
