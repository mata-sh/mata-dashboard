<?php

declare(strict_types=1);

namespace Mata\Services\Auth;

use Mata\Core\Session;
use Mata\Models\Auth\User;
use Mata\Models\Auth\UserRepository;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Signs users in and out, and validates the session on each request.
 *
 * - Unknown and locked accounts take as long to reject as a wrong password.
 * - Five failed attempts lock an account for 15 minutes.
 * - Sessions hold the account's auth revision; changing the password or role signs it out everywhere.
 * - Remember-me cookies carry a single-use token, rotated on every automatic sign-in.
 */
class UserAuthenticator
{
    public const REMEMBER_COOKIE = 'remember_token';

    private const MAX_ATTEMPTS = 5;
    private const LOCKOUT_SECONDS = 15 * 60;
    private const REMEMBER_LIFETIME_SECONDS = 30 * 24 * 60 * 60;

    public function __construct(
        private readonly UserRepository $users,
        private readonly LoggerInterface $logger,
        private readonly bool $secureRequest,
    ) {}

    /**
     * Sign in from a remember-me cookie when the session is not authenticated yet.
     *
     * Failures are logged instead of thrown, so a users file that cannot be written does not break every page.
     */
    public function resumeFromRememberCookie(?string $cookie): void
    {
        if ($cookie === null || $this->check()) {
            return;
        }

        [$username, $token] = explode('|', $cookie . '|');
        if ($this->users->find($username)?->hasValidRememberToken($token, time()) !== true) {
            return;
        }

        $rotatedToken = self::newToken();

        try {
            // Persist the rotation before publishing authenticated state.
            $rotated = $this->users->updateUser($username, static function (User $user) use ($token, $rotatedToken): ?User {
                // Another request may have rotated or revoked the token since it was read.
                if (!$user->hasValidRememberToken($token, time())) {
                    return null;
                }

                return $user->withRememberToken($rotatedToken, time() + self::REMEMBER_LIFETIME_SECONDS);
            });
        } catch (RuntimeException $e) {
            $this->logger->warning('Remember-me sign-in skipped', ['username' => $username, 'error' => $e->getMessage()]);

            return;
        }

        if ($rotated) {
            $this->sendRememberCookie($username . '|' . $rotatedToken, time() + self::REMEMBER_LIFETIME_SECONDS);
            $this->startSession($username);
            $this->logger->info('Signed in with remember-me cookie', ['username' => $username]);
        }
    }

    /**
     * @return bool True on success, false for wrong credentials or a locked account
     *
     * @throws RuntimeException If the users file cannot be updated
     */
    public function login(string $username, string $password, bool $remember = false): bool
    {
        try {
            return $this->attemptLogin($username, $password, $remember);
        } catch (RuntimeException $e) {
            $this->logger->error('Sign-in could not update the users file', ['username' => $username, 'error' => $e->getMessage()]);

            throw $e;
        }
    }

    public function logout(): void
    {
        $username = $this->getUsername();
        if ($username !== null && $this->users->find($username)?->rememberTokenHash !== null) {
            $this->users->updateUser($username, static fn (User $user): ?User => $user->rememberTokenHash === null ? null : $user->withoutRememberToken());
        }

        Session::destroy($this->secureRequest);
        $this->sendRememberCookie('', time() - 3600);

        $this->logger->info('Signed out', ['username' => $username]);
    }

    /**
     * Whether the session belongs to an account whose auth revision is still current.
     *
     * Read-only by design: stale sessions are ignored, not cleared, so check() never has side effects.
     */
    public function check(): bool
    {
        return $this->sessionAccount() !== null;
    }

    /** @return null|array{username: string, role: string, id: string, auth_revision: string} */
    public function getUser(): ?array
    {
        $user = $this->sessionAccount();

        return $user === null ? null : self::sessionUser($user);
    }

    public function getUsername(): ?string
    {
        return $this->sessionAccount()?->username;
    }

    public function hasRole(string $role): bool
    {
        return $this->sessionAccount()?->role === $role;
    }

    private function attemptLogin(string $username, string $password, bool $remember): bool
    {
        $user = $this->users->find($username);

        // Verify before any early return, so unknown and locked accounts take as long to reject as a wrong password.
        $passwordValid = password_verify($password, $user->passwordHash ?? self::dummyPasswordHash());

        if ($user === null) {
            $this->logger->warning('Sign-in attempt for unknown user', ['username' => $username]);

            return false;
        }
        if ($user->isLocked(time())) {
            $this->logger->warning('Sign-in attempt on locked account', ['username' => $username]);

            return false;
        }
        if (!$passwordValid) {
            $this->recordFailedAttempt($username);
            $this->logger->warning('Failed sign-in', ['username' => $username]);

            return false;
        }

        // Hash outside the exclusive lock; bcrypt is deliberately slow.
        $rehash = password_needs_rehash($user->passwordHash, PASSWORD_DEFAULT) ? password_hash($password, PASSWORD_DEFAULT) : null;
        $rememberToken = $remember ? self::newToken() : null;
        $rememberExpires = time() + self::REMEMBER_LIFETIME_SECONDS;

        // Persist all changes before publishing the cookie or session.
        $signedIn = $this->users->updateUser($username, static function (User $current) use ($user, $rehash, $rememberToken, $rememberExpires): ?User {
            // Another request changed the password or locked the account after it was verified.
            if ($current->passwordHash !== $user->passwordHash || $current->isLocked(time())) {
                return null;
            }

            $current = $current->withoutLockout();
            if ($rehash !== null) {
                $current = $current->withPasswordHash($rehash);
            }

            return $rememberToken === null ? $current : $current->withRememberToken($rememberToken, $rememberExpires);
        });

        if (!$signedIn) {
            $this->logger->warning('Sign-in rejected: account changed during sign-in', ['username' => $username]);

            return false;
        }

        if ($rememberToken !== null) {
            $this->sendRememberCookie($username . '|' . $rememberToken, $rememberExpires);
        }
        $this->startSession($username);
        $this->logger->info('Signed in', ['username' => $username, 'password_rehashed' => $rehash !== null]);

        return true;
    }

    private function recordFailedAttempt(string $username): void
    {
        $this->users->updateUser(
            $username,
            static fn (User $user): User => $user->withFailedAttempt(time(), self::MAX_ATTEMPTS, self::LOCKOUT_SECONDS),
        );
    }

    /** The stored account the session belongs to, if its auth revision is still current. */
    private function sessionAccount(): ?User
    {
        $sessionUser = Session::get('user');
        if (Session::get('logged_in') !== true || !is_array($sessionUser) || !is_string($sessionUser['username'] ?? null)) {
            return null;
        }

        $user = $this->users->find($sessionUser['username']);
        $revision = $sessionUser['auth_revision'] ?? null;

        return $user !== null && is_string($revision) && hash_equals($user->authRevision, $revision) ? $user : null;
    }

    private function startSession(string $username): void
    {
        $user = $this->users->find($username) ?? throw new RuntimeException("User disappeared after sign-in: {$username}");

        Session::regenerate();
        Session::set('user', self::sessionUser($user));
        Session::set('logged_in', true);
    }

    /** @return array{username: string, role: string, id: string, auth_revision: string} */
    private static function sessionUser(User $user): array
    {
        return [
            'username' => $user->username,
            'role' => $user->role,
            'id' => $user->id,
            'auth_revision' => $user->authRevision,
        ];
    }

    private function sendRememberCookie(string $value, int $expires): void
    {
        setcookie(self::REMEMBER_COOKIE, $value, [
            'expires' => $expires,
            'path' => '/',
            'secure' => $this->secureRequest,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    /**
     * A well-formed bcrypt hash at the default cost, for users that do not exist.
     *
     * password_verify() returns immediately for a malformed hash, and the default cost differs between PHP versions.
     */
    private static function dummyPasswordHash(): string
    {
        return sprintf('$2y$%02d$', PASSWORD_BCRYPT_DEFAULT_COST) . str_repeat('.', 53);
    }

    private static function newToken(): string
    {
        return bin2hex(random_bytes(32));
    }
}
