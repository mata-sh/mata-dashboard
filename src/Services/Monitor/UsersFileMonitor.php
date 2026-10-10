<?php

declare(strict_types=1);

namespace Mata\Services\Monitor;

use Mata\Services\Event\Dto\EventCommand;
use Mata\Services\Event\EventManager;
use Psr\Log\LoggerInterface;

/**
 * Raises security events from the users file: permission drift, accounts locked by failed logins,
 * and accounts created, deleted or given a new role since the previous run.
 *
 * UserAuthenticator already counts failed logins and writes `locked_until`, so this monitor only reports that
 * state. Event volume is bounded by the number of users, not by login traffic.
 */
final class UsersFileMonitor
{
    /**
     * @param string $snapshotFile Username-to-role map from the previous run. The first run, or a
     *                             missing or unreadable snapshot, only records a baseline.
     */
    public function __construct(
        private readonly string $usersFile,
        private readonly string $snapshotFile,
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

        $users = $this->readUsers();
        if ($users === null) {
            $this->logger->warning('Users file monitor could not read users file', ['path' => $this->usersFile]);

            return;
        }

        $this->checkLockedAccounts($users, $now);
        $this->checkAccountChanges($users);
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

    /** @param array<array-key,array<mixed>> $users */
    private function checkLockedAccounts(array $users, int $now): void
    {
        foreach ($users as $username => $user) {
            $lockedUntil = $user['locked_until'] ?? null;
            if (!is_int($lockedUntil) || $lockedUntil <= $now) {
                continue;
            }

            $this->eventManager->handle(new EventCommand(
                type: 'user_account_locked',
                message: "Account {$username} is locked after repeated failed logins",
                level: 'warning',
                context: ['username' => $username],
            ));
        }
    }

    /** @param array<array-key,array<mixed>> $users */
    private function checkAccountChanges(array $users): void
    {
        $roles = [];
        foreach ($users as $username => $user) {
            $roles[$username] = is_string($user['role'] ?? null) ? $user['role'] : null;
        }
        ksort($roles);

        $previous = $this->readSnapshot();
        if ($previous === $roles) {
            return;
        }

        if ($previous !== null) {
            foreach (array_diff_key($roles, $previous) as $username => $role) {
                $this->recordAccountChange(
                    "Account {$username} was created with role " . ($role ?? 'none'),
                    ['username' => $username, 'change' => 'created', 'role' => $role],
                );
            }
            foreach (array_diff_key($previous, $roles) as $username => $role) {
                $this->recordAccountChange(
                    "Account {$username} was deleted",
                    ['username' => $username, 'change' => 'deleted', 'role' => $role],
                );
            }
            foreach (array_intersect_key($roles, $previous) as $username => $role) {
                if ($role !== $previous[$username]) {
                    $this->recordAccountChange(
                        "Account {$username} role changed from " . ($previous[$username] ?? 'none') . ' to ' . ($role ?? 'none'),
                        ['username' => $username, 'change' => 'role_changed', 'role' => $role, 'previous_role' => $previous[$username]],
                    );
                }
            }
        }

        $this->writeSnapshot($roles);
    }

    /** @param array<string,mixed> $context */
    private function recordAccountChange(string $message, array $context): void
    {
        $this->eventManager->handle(new EventCommand(
            type: 'user_account_changed',
            message: $message,
            level: 'warning',
            context: $context,
        ));
    }

    /** @return null|array<array-key,array<mixed>> Users keyed by username, as UserRepository indexes them. */
    private function readUsers(): ?array
    {
        $content = @file_get_contents($this->usersFile);
        $decoded = $content === false ? null : json_decode($content, true);
        if (!is_array($decoded)) {
            return null;
        }

        $users = [];
        foreach ($decoded as $user) {
            if (is_array($user) && is_string($user['username'] ?? null)) {
                $users[$user['username']] = $user;
            }
        }

        return $users;
    }

    /** @return null|array<array-key,?string> */
    private function readSnapshot(): ?array
    {
        if (!is_file($this->snapshotFile)) {
            return null;
        }

        $content = @file_get_contents($this->snapshotFile);
        $decoded = $content === false ? null : json_decode($content, true);
        if (!is_array($decoded)) {
            $this->logger->warning('Users file monitor snapshot is unreadable; recording a new baseline', [
                'path' => $this->snapshotFile,
            ]);

            return null;
        }

        $roles = [];
        foreach ($decoded as $username => $role) {
            // Numeric usernames decode to int keys, matching how readUsers() indexes them.
            if ($role === null || is_string($role)) {
                $roles[$username] = $role;
            }
        }
        ksort($roles);

        return $roles;
    }

    /** @param array<array-key,?string> $roles */
    private function writeSnapshot(array $roles): void
    {
        $temp = $this->snapshotFile . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $written = @file_put_contents($temp, json_encode($roles, JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT)) !== false
            && @chmod($temp, 0o600)
            && @rename($temp, $this->snapshotFile);

        if (!$written) {
            @unlink($temp);
            $this->logger->warning('Users file monitor could not write snapshot', ['path' => $this->snapshotFile]);
        }
    }
}
