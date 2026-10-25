<?php

declare(strict_types=1);

namespace Mata\Cli\Command\User;

use Mata\Cli\CliOutput;
use Mata\Services\Auth\UserAdministrator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

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
        // In prod the typed confirmation already ran.
        $refused = $this->env === 'prod' ? null : CliOutput::confirm($input, $output, "Delete user '{$username}'?");
        if ($refused !== null) {
            return $refused;
        }

        $users->deleteUser($username);
        $this->audit->warning('User deleted', ['target_user' => $username]);

        return CliOutput::ok($input, $output, "User deleted: {$username}", ['username' => $username]);
    }
}
