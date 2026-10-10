<?php

declare(strict_types=1);

namespace Mata\Services\Monitor;

use Mata\Services\Event\Dto\EventCommand;
use Mata\Services\Event\EventManager;
use Psr\Log\LoggerInterface;

/**
 * Raises security events from the users file: permission drift and accounts locked by failed logins.
 *
 * Auth already counts failed logins and writes `locked_until`, so this monitor only reports that
 * state. Event volume is bounded by the number of users, not by login traffic.
 */
final class UsersFileMonitor
{
    public function __construct(
        private readonly string $usersFile,
        private readonly EventManager $eventManager,
        private readonly LoggerInterface $logger,
    ) {}

    public function check(int $now): void
    {
        clearstatcache(true, $this->usersFile);
        if (!is_file($this->usersFile)) {
            return;
        }

        $this->checkPermissions();
        $this->checkLockedAccounts($now);
    }

    private function checkPermissions(): void
    {
        $perms = fileperms($this->usersFile);
        if ($perms === false || ($perms & 0o077) === 0) {
            return;
        }

        $mode = sprintf('%04o', $perms & 0o777);
        $this->eventManager->handle(new EventCommand(
            type: 'users_file_insecure',
            message: "Users file is accessible to group or others (mode {$mode}); run chmod 600",
            level: 'critical',
            context: ['mode' => $mode],
        ));
    }

    private function checkLockedAccounts(int $now): void
    {
        $content = @file_get_contents($this->usersFile);
        $users = $content === false ? null : json_decode($content, true);
        if (!is_array($users)) {
            $this->logger->warning('Users file monitor could not read users file', ['path' => $this->usersFile]);

            return;
        }

        foreach ($users as $user) {
            if (!is_array($user) || !is_string($user['username'] ?? null)) {
                continue;
            }

            $lockedUntil = $user['locked_until'] ?? null;
            if (!is_int($lockedUntil) || $lockedUntil <= $now) {
                continue;
            }

            $this->eventManager->handle(new EventCommand(
                type: 'user_account_locked',
                message: "Account {$user['username']} is locked after repeated failed logins",
                level: 'warning',
                context: ['username' => $user['username']],
            ));
        }
    }
}
