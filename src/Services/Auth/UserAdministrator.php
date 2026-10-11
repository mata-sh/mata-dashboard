<?php

declare(strict_types=1);

namespace Mata\Services\Auth;

use InvalidArgumentException;
use Mata\Models\Auth\User;
use Mata\Models\Auth\UserRepository;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Account management for `scripts/user-management/manage-users.php`.
 *
 * Performs no role checks: whoever can run the script can already edit users.json directly.
 * Web requests only get a UserAuthenticator.
 */
final class UserAdministrator
{
    public const ROLES = ['viewer', 'admin'];

    private const USERNAME_PATTERN = '/^[A-Za-z0-9._-]{1,64}$/';

    public function __construct(
        private readonly UserRepository $users,
        private readonly LoggerInterface $logger,
    ) {}

    /** @return array<string,User> Users indexed by username */
    public function listUsers(): array
    {
        return $this->users->all();
    }

    /**
     * @throws InvalidArgumentException If the username or role is invalid
     * @throws RuntimeException         If the user already exists
     */
    public function createUser(string $username, string $password, string $role = 'viewer'): void
    {
        if (preg_match(self::USERNAME_PATTERN, $username) !== 1) {
            throw new InvalidArgumentException('Username must be 1-64 characters: letters, digits, dot, underscore or hyphen');
        }
        self::assertValidRole($role);
        $user = User::create($username, password_hash($password, PASSWORD_DEFAULT), $role);

        $this->users->update(static function (array $users) use ($user): array {
            if (isset($users[$user->username])) {
                throw new RuntimeException("User already exists: {$user->username}");
            }
            $users[$user->username] = $user;

            return $users;
        });
        $this->logger->info('Created user', ['username' => $username, 'role' => $role]);
    }

    /** @throws RuntimeException If the user does not exist */
    public function deleteUser(string $username): void
    {
        $this->users->update(static function (array $users) use ($username): array {
            if (!isset($users[$username])) {
                throw self::unknownUser($username);
            }
            unset($users[$username]);

            return $users;
        });
        $this->logger->info('Deleted user', ['username' => $username]);
    }

    /**
     * Set a new password, clear any lockout and sign the account out everywhere.
     *
     * @throws RuntimeException If the user does not exist
     */
    public function changePassword(string $username, string $newPassword): void
    {
        $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);

        $this->updateExistingUser($username, static fn (User $user): User => $user
            ->withPasswordHash($passwordHash)
            ->withoutLockout()
            ->withSessionsRevoked());
        $this->logger->info('Changed password', ['username' => $username]);
    }

    /**
     * Set a new role and sign the account out everywhere, so no session keeps the old role.
     *
     * @throws InvalidArgumentException If the role is invalid
     * @throws RuntimeException         If the user does not exist
     */
    public function changeRole(string $username, string $role): void
    {
        self::assertValidRole($role);

        $this->updateExistingUser($username, static fn (User $user): User => $user->withRole($role)->withSessionsRevoked());
        $this->logger->info('Changed role', ['username' => $username, 'role' => $role]);
    }

    /** @throws RuntimeException If the user does not exist */
    public function unlockUser(string $username): void
    {
        $this->updateExistingUser($username, static fn (User $user): User => $user->withoutLockout());
        $this->logger->info('Unlocked user', ['username' => $username]);
    }

    /** @param callable(User): User $change */
    private function updateExistingUser(string $username, callable $change): void
    {
        if (!$this->users->updateUser($username, $change)) {
            throw self::unknownUser($username);
        }
    }

    private static function assertValidRole(string $role): void
    {
        if (!in_array($role, self::ROLES, true)) {
            throw new InvalidArgumentException('Role must be one of: ' . implode(', ', self::ROLES));
        }
    }

    private static function unknownUser(string $username): RuntimeException
    {
        return new RuntimeException("User does not exist: {$username}");
    }
}
