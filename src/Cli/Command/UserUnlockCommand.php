<?php

declare(strict_types=1);

namespace Mata\Cli\Command;

use Mata\Cli\CliOutput;
use Mata\Services\Auth\UserAdministrator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'user:unlock', description: 'Unlock a user locked out after failed logins')]
final class UserUnlockCommand extends AbstractUserCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->addArgument('username', InputArgument::REQUIRED);
    }

    protected function writes(): bool
    {
        return true;
    }

    protected function handle(UserAdministrator $users, InputInterface $input, OutputInterface $output): int
    {
        $username = (string) $input->getArgument('username');
        $users->unlockUser($username);
        $this->audit->info('User unlocked successfully', ['target_user' => $username]);

        return CliOutput::ok($input, $output, "User unlocked: {$username}", ['username' => $username]);
    }
}
