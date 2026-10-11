<?php

declare(strict_types=1);

namespace Mata\Cli\Command;

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
        $password = $this->readPassword($input, $output);
        if ($password === null) {
            return $this->fail($input, $output, 'No password: pass --password-stdin or --generate-password without a terminal.');
        }
        [$secret, $generated] = $password;
        $policyError = $this->passwordPolicyError($secret);
        if ($policyError !== null) {
            return $this->fail($input, $output, $policyError);
        }

        $users->changePassword($username, $secret);
        $this->audit->warning('Password changed', ['operator' => $this->operator, 'target_user' => $username, 'environment' => $this->env]);

        $message = "Password changed for {$username}" . ($generated ? "\nPassword (shown once): {$secret}" : '');

        return $this->succeed($input, $output, $message, ['username' => $username] + ($generated ? ['password' => $secret] : []));
    }
}
