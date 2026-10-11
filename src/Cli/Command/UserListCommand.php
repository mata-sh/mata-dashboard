<?php

declare(strict_types=1);

namespace Mata\Cli\Command;

use Mata\Cli\CliOutput;
use Mata\Services\Auth\UserAdministrator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'user:list', description: 'List dashboard users')]
final class UserListCommand extends AbstractUserCommand
{
    protected function writes(): bool
    {
        return false;
    }

    protected function handle(UserAdministrator $users, InputInterface $input, OutputInterface $output): int
    {
        $now = time();
        $rows = [];
        foreach ($users->listUsers() as $user) {
            $rows[] = ['username' => $user->username, 'role' => $user->role, 'locked' => $user->isLocked($now), 'created_at' => $user->createdAt];
        }
        $this->audit->info('Users list accessed', ['operator' => $this->operator, 'environment' => $this->env, 'user_count' => count($rows)]);

        if ($input->getOption('json')) {
            CliOutput::json($output, ['status' => 'ok', 'users' => $rows]);

            return CliOutput::EXIT_OK;
        }
        if ($rows === []) {
            $output->writeln('No users. Create one with: bin/mata user create <username> <role>');
        }
        foreach ($rows as $row) {
            $output->writeln("  {$row['username']} ({$row['role']})" . ($row['locked'] ? ' <comment>[locked]</comment>' : ''));
        }

        return CliOutput::EXIT_OK;
    }
}
