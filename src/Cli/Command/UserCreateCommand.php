<?php

declare(strict_types=1);

namespace Mata\Cli\Command;

use Mata\Cli\CliOutput;
use Mata\Services\Auth\UserAdministrator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'user:create', description: 'Create a dashboard user')]
final class UserCreateCommand extends AbstractUserCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this
            ->addArgument('username', InputArgument::REQUIRED)
            ->addArgument('role', InputArgument::OPTIONAL, 'viewer or admin', 'viewer');
        $this->addPasswordOptions();
        $this->setHelp('Without a terminal pass --password-stdin or --generate-password; a generated password is printed once.');
    }

    protected function writes(): bool
    {
        return true;
    }

    protected function handle(UserAdministrator $users, InputInterface $input, OutputInterface $output): int
    {
        $username = (string) $input->getArgument('username');
        $role = (string) $input->getArgument('role');
        [$secret, $generated] = $this->newPassword($input, $output);

        $users->createUser($username, $secret, $role);
        $this->audit->info('User created successfully', ['target_user' => $username, 'role' => $role]);

        $message = "User created: {$username} ({$role})" . ($generated ? "\nPassword (shown once): {$secret}" : '');

        return CliOutput::ok($input, $output, $message, ['username' => $username, 'role' => $role] + ($generated ? ['password' => $secret] : []));
    }
}
