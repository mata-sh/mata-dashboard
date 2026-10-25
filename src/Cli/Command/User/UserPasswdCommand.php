<?php

declare(strict_types=1);

namespace Mata\Cli\Command\User;

use Mata\Cli\CliOutput;
use Mata\Services\Auth\UserAdministrator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'user:passwd', description: "Change a dashboard user's password")]
final class UserPasswdCommand extends AbstractUserCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->addArgument('username', InputArgument::REQUIRED);
        $this->addPasswordOptions();
    }

    protected function writes(): bool
    {
        return true;
    }

    protected function handle(UserAdministrator $users, InputInterface $input, OutputInterface $output): int
    {
        $username = (string) $input->getArgument('username');
        [$secret, $generated] = $this->newPassword($input, $output);

        $users->changePassword($username, $secret);
        $this->audit->warning('Password changed', ['target_user' => $username]);

        $message = "Password changed for {$username}" . ($generated ? "\nPassword (shown once): {$secret}" : '');

        return CliOutput::ok($input, $output, $message, ['username' => $username] + ($generated ? ['password' => $secret] : []));
    }
}
