<?php

declare(strict_types=1);

namespace Mata\Core;

use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Authentication and user management system.
 *
 * Provides secure session-based authentication with JSON file storage,
 * including features like:
 * - Password hashing and verification with bcrypt
 * - Account lockout after failed attempts
 * - Secure remember-me functionality with token-based cookies
 * - User management (create, delete, update)
 * - Role-based access control
 * - Audit logging for security events
 * - Automatic password rehashing for algorithm upgrades
 * - Timing attack prevention
 * - File permission validation
 */
class Auth
{
    private string $usersFile;

    /** @var array<string,array<string,mixed>> */
    private array $users = [];
    private const MAX_ATTEMPTS = 5;
    private const LOCKOUT_TIME_SECONDS = 900; // 15 minutes
    private const ROLES = ['viewer', 'admin'];
    private const USERNAME_PATTERN = '/^[A-Za-z0-9._-]{1,64}$/';
    private const REMEMBER_LIFETIME_SECONDS = 30 * 24 * 60 * 60;

    /** @var bool CLI mode bypasses role checks for user management */
    private bool $cliMode;
    private LoggerInterface $logger;
    private readonly bool $secureRequest;
    private readonly ?string $rememberToken;
    private ?string $cliScriptPath;

    /**
     * Initialize authentication system.
     *
     * @param LoggerInterface $logger        Logger instance for audit logging
     * @param string          $usersFile     Absolute path to users.json file
     * @param bool            $secureRequest Whether the request is secure
     * @param null|string     $rememberToken Remember-me cookie value
     * @param bool            $cliMode       Enable CLI mode (bypasses role checks, default: false)
     *
     * @throws RuntimeException If users file is missing or has insecure permissions
     */
    public function __construct(
        LoggerInterface $logger,
        string $usersFile,
        bool $secureRequest,
        ?string $rememberToken,
        bool $cliMode = false,
        ?string $cliScriptPath = null
    ) {
        $this->usersFile = $usersFile;
        $this->logger = $logger;
        $this->secureRequest = $secureRequest;
        $this->rememberToken = $rememberToken;
        $this->cliMode = $cliMode;
        $this->cliScriptPath = $cliScriptPath;

        $this->loadUsers();

        if (!$cliMode) {
            $this->checkRememberMe();
        }
    }

    /**
     * Load users from JSON file and validate file security.
     *
     * @throws RuntimeException If file cannot be read or has invalid JSON
     */
    private function loadUsers(): void
    {
        if (!file_exists($this->usersFile)) {
            $this->users = [];

            if ($this->cliMode) {
                // In CLI mode, initialize empty users array (file will be created on first save)
                return;
            }

            throw new RuntimeException("Users file not found. {$this->usersFile}");
        }

        $this->withFileLock(LOCK_SH, function () {
            // Check file permissions (relaxed in CLI mode)
            $perms = fileperms($this->usersFile);
            if (!$this->cliMode && $perms !== false && ($perms & 0o077) !== 0) {
                throw new RuntimeException("Users file has insecure permissions. Run: chmod 600 {$this->usersFile}");
            }

            if (!is_readable($this->usersFile)) {
                if ($this->cliMode) {
                    $owner = fileowner($this->usersFile);
                    $ownerInfo = is_int($owner) ? posix_getpwuid($owner) : false;
                    $ownerName = is_array($ownerInfo) ? $ownerInfo['name'] : '<file-owner>';

                    throw new RuntimeException(
                        "Users file not readable: {$this->usersFile}\n"
                        . 'Try running with: sudo -u ' . $ownerName
                        . ' php ' . ($this->cliScriptPath ?? '<script-path>')
                    );
                }

                throw new RuntimeException("Users file not readable: {$this->usersFile}");
            }

            $content = $this->readUsersFile() ?? throw new RuntimeException("Users file not found: {$this->usersFile}");
            $this->users = self::decodeUsers($content);
            $this->logger->info('Loaded users file');
        });
    }

    /**
     * @return array<string,array<string,mixed>> Users indexed by username
     *
     * @throws RuntimeException If the content is not valid JSON
     */
    private static function decodeUsers(string $content): array
    {
        $users = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Invalid JSON in users file: ' . json_last_error_msg());
        }

        $indexed = [];
        foreach ($users as $user) {
            if (isset($user['username'])) {
                $indexed[$user['username']] = $user;
            }
        }

        return $indexed;
    }

    /**
     * Authenticate user and create session.
     *
     * @param string $username Username to authenticate
     * @param string $password Plain text password
     * @param bool   $remember Whether to create remember-me cookie (default: false)
     *
     * @return bool True on successful login, false on failure
     *
     * @throws RuntimeException If the users file cannot be updated
     */
    public function login(string $username, string $password, bool $remember = false): bool
    {
        try {
            return $this->attemptLogin($username, $password, $remember);
        } catch (RuntimeException $e) {
            $this->logger->error("Login could not update the users file for {$username}: {$e->getMessage()}");

            throw $e;
        }
    }

    private function attemptLogin(string $username, string $password, bool $remember): bool
    {
        $user = $this->users[$username] ?? null;

        // Verify before any early return, so unknown and locked accounts take as long to reject as a wrong password.
        $passwordValid = password_verify($password, $user['password_hash'] ?? self::dummyPasswordHash());

        if (!$user) {
            $this->logger->warning("Login attempt for non-existent user: {$username}");

            return false;
        }

        if (self::isLocked($user)) {
            $this->logger->warning("Login attempt on locked account: {$username}");

            return false;
        }

        if (!$passwordValid) {
            $this->recordFailedAttempt($username);
            $this->logger->warning("Failed login for user: {$username}");

            return false;
        }

        // Hash outside the exclusive lock; bcrypt is deliberately slow.
        $verifiedHash = $user['password_hash'];
        $rehash = password_needs_rehash($verifiedHash, PASSWORD_DEFAULT) ? password_hash($password, PASSWORD_DEFAULT) : null;
        $rememberToken = $remember ? bin2hex(random_bytes(32)) : null;

        // Persist all changes before publishing the cookie or session.
        $signedIn = $this->updateUsers(function () use ($username, $verifiedHash, $rehash, $rememberToken): bool {
            $user = $this->users[$username] ?? null;
            // Another request changed the password or locked the account after it was verified.
            if ($user === null || $user['password_hash'] !== $verifiedHash || self::isLocked($user)) {
                return false;
            }

            if ($rehash !== null) {
                $this->users[$username]['password_hash'] = $rehash;
                $this->logger->info("Password rehashed for user: {$username}");
            }
            $this->users[$username]['failed_attempts'] = 0;
            unset($this->users[$username]['locked_until']);
            $this->backfillAuthRevision($username);
            if ($rememberToken !== null) {
                $this->issueRememberToken($username, $rememberToken);
            }

            return true;
        });

        if (!$signedIn) {
            $this->logger->warning("Login rejected, account changed during sign-in: {$username}");

            return false;
        }

        if ($rememberToken !== null) {
            $this->sendRememberCookie($username, $rememberToken);
        }
        $this->authenticateSession($username);

        $this->logger->info("Successful login: {$username}");

        return true;
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

    /** @param array<string,mixed> $user */
    private static function isLocked(array $user): bool
    {
        return isset($user['locked_until']) && time() < $user['locked_until'];
    }

    /** Accounts created before revision-based sessions get a revision on their next sign-in. */
    private function backfillAuthRevision(string $username): void
    {
        $this->users[$username]['auth_revision'] ??= bin2hex(random_bytes(32));
    }

    private function assertValidRole(string $role): void
    {
        if (!in_array($role, self::ROLES, true)) {
            throw new InvalidArgumentException('Role must be one of: ' . implode(', ', self::ROLES));
        }
    }

    private function assertUserExists(string $username): void
    {
        if (!isset($this->users[$username])) {
            throw new RuntimeException("User does not exist: {$username}");
        }
    }

    private function rotateAuthRevision(string $username): void
    {
        $this->users[$username]['auth_revision'] = bin2hex(random_bytes(32));
        unset($this->users[$username]['remember_token'], $this->users[$username]['remember_expires']);
    }

    /** @return array<string,mixed> */
    private function sessionUser(string $username): array
    {
        $user = $this->users[$username];

        return [
            'username' => $username,
            'role' => $user['role'],
            'id' => $user['id'],
            'auth_revision' => $user['auth_revision'],
        ];
    }

    private function authenticateSession(string $username): void
    {
        Session::regenerate();
        Session::set('user', $this->sessionUser($username));
        Session::set('logged_in', true);
    }

    /**
     * Replace the user's remember token in memory.
     *
     * The caller must save before calling sendRememberCookie().
     */
    private function issueRememberToken(string $username, string $token): void
    {
        $this->users[$username]['remember_token'] = hash('sha256', $token);
        $this->users[$username]['remember_expires'] = time() + self::REMEMBER_LIFETIME_SECONDS;
    }

    private function rememberTokenMatches(string $username, string $token): bool
    {
        $user = $this->users[$username] ?? null;

        return isset($user['remember_token'], $user['remember_expires'])
            && hash_equals($user['remember_token'], hash('sha256', $token))
            && time() < $user['remember_expires'];
    }

    private function sendRememberCookie(string $username, string $token): void
    {
        setcookie(
            'remember_token',
            $username . '|' . $token,
            [
                'expires' => time() + self::REMEMBER_LIFETIME_SECONDS,
                'path' => '/',
                'secure' => $this->secureRequest,
                'httponly' => true,
                'samesite' => 'Lax',
            ]
        );
    }

    /**
     * Check and process remember-me cookie for automatic login.
     */
    private function checkRememberMe(): void
    {
        if ($this->check() || $this->rememberToken === null) {
            return;
        }

        [$username, $token] = explode('|', $this->rememberToken . '|');
        if (!$this->rememberTokenMatches($username, $token)) {
            return;
        }

        // Persist token rotation before publishing authenticated state.
        $rotatedToken = bin2hex(random_bytes(32));

        try {
            $rotated = $this->updateUsers(function () use ($username, $token, $rotatedToken): bool {
                // Another request may have rotated or revoked the token since it was read.
                if (!$this->rememberTokenMatches($username, $token)) {
                    return false;
                }

                $this->backfillAuthRevision($username);
                $this->issueRememberToken($username, $rotatedToken);

                return true;
            });
        } catch (RuntimeException $e) {
            // Skip auto-login rather than failing every page.
            $this->logger->warning("Remember-me skipped for {$username}: {$e->getMessage()}");

            return;
        }

        if (!$rotated) {
            return;
        }

        $this->sendRememberCookie($username, $rotatedToken);
        $this->authenticateSession($username);

        $this->logger->info("Auto-login via remember token: {$username}");
    }

    /**
     * Record failed login attempt and implement account lockout.
     *
     * @param string $username Username that failed authentication
     */
    private function recordFailedAttempt(string $username): void
    {
        $this->updateUsers(function () use ($username): bool {
            if (!isset($this->users[$username])) {
                return false;
            }

            // Reset the attempt counter after an expired lockout.
            if (isset($this->users[$username]['locked_until'])
                && time() >= $this->users[$username]['locked_until']) {
                $this->users[$username]['failed_attempts'] = 0;
                unset($this->users[$username]['locked_until']);
            }

            $this->users[$username]['failed_attempts']
                = ($this->users[$username]['failed_attempts'] ?? 0) + 1;

            if ($this->users[$username]['failed_attempts'] >= self::MAX_ATTEMPTS) {
                $this->users[$username]['locked_until'] = time() + self::LOCKOUT_TIME_SECONDS;
            }

            return true;
        });
    }

    /**
     * Reload users under an exclusive lock, apply a change and save the result.
     *
     * The change always sees the latest file, so concurrent requests never overwrite each other.
     * It re-checks its own preconditions against that file and returns false to skip the write.
     *
     * @param callable(): bool $change Mutates $this->users
     *
     * @return bool Whether the change was saved
     */
    private function updateUsers(callable $change): bool
    {
        $this->assertUsersDirectoryWritable();

        return $this->withFileLock(LOCK_EX, function () use ($change): bool {
            $content = $this->readUsersFile();
            $this->users = $content === null ? [] : self::decodeUsers($content);

            if (!$change()) {
                return false;
            }

            $this->writeUsers();

            return true;
        });
    }

    /**
     * Atomically replace the users file with secure permissions. Callers hold the exclusive lock.
     */
    private function writeUsers(): void
    {
        $encoded = json_encode(array_values($this->users), JSON_PRETTY_PRINT);
        if ($encoded === false) {
            throw new RuntimeException('Failed to encode users JSON');
        }

        $directory = dirname($this->usersFile);
        $temp = tempnam($directory, basename($this->usersFile) . '.');
        if ($temp === false || realpath(dirname($temp)) !== realpath($directory)) {
            if ($temp !== false) {
                @unlink($temp);
            }

            throw new RuntimeException("Failed to create temp users file in: {$directory}");
        }

        try {
            if (!chmod($temp, 0600) || file_put_contents($temp, $encoded) === false) {
                throw new RuntimeException("Failed to write temp users file: {$temp}");
            }

            if (!rename($temp, $this->usersFile)) {
                throw new RuntimeException("Failed to replace users file: {$this->usersFile}");
            }
        } finally {
            if (file_exists($temp)) {
                @unlink($temp);
            }
        }

        if (!chmod($this->usersFile, 0600)) {
            throw new RuntimeException("Failed to secure users file: {$this->usersFile}");
        }
    }

    /**
     * Return the users file contents, or null when the file does not exist yet.
     *
     * @throws RuntimeException If the file exists but cannot be read
     */
    private function readUsersFile(): ?string
    {
        if (!file_exists($this->usersFile)) {
            return null;
        }

        $content = file_get_contents($this->usersFile);
        if ($content === false) {
            throw new RuntimeException("Failed to read users file: {$this->usersFile}");
        }

        return $content;
    }

    private function assertUsersDirectoryWritable(): void
    {
        $directory = dirname($this->usersFile);
        if (!is_dir($directory) || !is_writable($directory)) {
            throw new RuntimeException(
                "Users directory is not writable: {$directory}. "
                . 'The PHP process needs write access to atomically update users.json.'
            );
        }
    }

    /**
     * Open the lock file handle for coordinating access to users.json.
     *
     * @return resource
     */
    private function openUsersLock()
    {
        $lockFile = $this->usersFile . '.lock';
        $lockHandle = fopen($lockFile, 'c');
        if ($lockHandle === false) {
            throw new RuntimeException("Failed to open users lock file: {$lockFile}");
        }
        if (!chmod($lockFile, 0600)) {
            fclose($lockHandle);

            throw new RuntimeException("Failed to secure users lock file: {$lockFile}");
        }

        return $lockHandle;
    }

    /**
     * Execute a callback with file locking, ensuring proper cleanup.
     *
     * @param 1|2      $lockType Lock type (LOCK_SH for read, LOCK_EX for write)
     * @param callable $callback Callback to execute while holding the lock
     *
     * @return mixed Return value from the callback
     *
     * @throws RuntimeException If lock cannot be acquired or callback throws
     */
    private function withFileLock(int $lockType, callable $callback)
    {
        $lockHandle = $this->openUsersLock();

        if (!flock($lockHandle, $lockType)) {
            fclose($lockHandle);
            $mode = $lockType === LOCK_SH ? 'reading' : 'writing';

            throw new RuntimeException("Failed to lock users file for {$mode}: {$this->usersFile}");
        }

        try {
            return $callback();
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }

    /**
     * Log out current user and clean up session/cookies.
     */
    public function logout(): void
    {
        $username = $this->getUsername();

        if ($username !== null && isset($this->users[$username]['remember_token'])) {
            $this->updateUsers(function () use ($username): bool {
                if (!isset($this->users[$username]['remember_token'])) {
                    return false;
                }
                unset($this->users[$username]['remember_token'], $this->users[$username]['remember_expires']);

                return true;
            });
        }

        Session::destroy($this->secureRequest);
        setcookie('remember_token', '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => $this->secureRequest,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        $this->logger->info('User logged out: ' . ($username ?? 'unknown'));
    }

    /**
     * Check if user is currently authenticated.
     *
     * @return bool True if user is logged in, false otherwise
     */
    public function check(): bool
    {
        $sessionUser = Session::get('user');

        return Session::get('logged_in') === true
            && is_array($sessionUser)
            && $this->isCurrentSession($sessionUser);
    }

    /**
     * Compare a session snapshot with the stored account it claims to represent.
     *
     * Read-only by design: stale snapshots are ignored, not cleared, so check() never has side effects.
     *
     * @param array<string,mixed> $sessionUser
     */
    private function isCurrentSession(array $sessionUser): bool
    {
        $username = $sessionUser['username'] ?? null;
        $user = is_string($username) ? ($this->users[$username] ?? null) : null;

        return $user !== null
            && is_string($user['auth_revision'] ?? null)
            && is_string($sessionUser['auth_revision'] ?? null)
            && hash_equals($user['auth_revision'], $sessionUser['auth_revision']);
    }

    /**
     * Get current authenticated user data.
     *
     * @return null|array<string,mixed> User data array or null if not logged in
     */
    public function getUser(): ?array
    {
        if (!$this->check()) {
            return null;
        }
        $sessionUser = Session::get('user');

        return $this->sessionUser($sessionUser['username']);
    }

    /**
     * Get current authenticated username.
     *
     * @return null|string Username or null if not logged in
     */
    public function getUsername(): ?string
    {
        $user = $this->getUser();

        return isset($user['username']) && is_string($user['username']) ? $user['username'] : null;
    }

    /**
     * Check if current user has specific role.
     *
     * @param string $role Role to check for
     *
     * @return bool True if user has the role, false otherwise
     */
    public function hasRole(string $role): bool
    {
        $user = $this->getUser();

        return ($user['role'] ?? '') === $role;
    }

    /**
     * Create a new user account.
     *
     * @param string $username Username for the new user
     * @param string $password Plain text password (will be hashed)
     * @param string $role     User role (default: 'viewer')
     *
     * @return bool True on success
     *
     * @throws RuntimeException If user already exists
     */
    public function createUser(string $username, string $password, string $role = 'viewer'): bool
    {
        if (!$this->cliMode && !$this->hasRole('admin')) {
            throw new RuntimeException('Unauthorized: Admin access required');
        }
        if (preg_match(self::USERNAME_PATTERN, $username) !== 1) {
            throw new InvalidArgumentException('Username must be 1-64 characters: letters, digits, dot, underscore or hyphen');
        }
        $this->assertValidRole($role);
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        $this->updateUsers(function () use ($username, $passwordHash, $role): bool {
            if (isset($this->users[$username])) {
                throw new RuntimeException("User already exists: {$username}");
            }

            $this->users[$username] = [
                'id' => uniqid('user_'),
                'username' => $username,
                'password_hash' => $passwordHash,
                'auth_revision' => bin2hex(random_bytes(32)),
                'role' => $role,
                'created_at' => date('Y-m-d H:i:s'),
                'failed_attempts' => 0,
            ];

            return true;
        });
        $this->logger->info("Created new user: {$username}");

        return true;
    }

    /**
     * Delete a user account.
     *
     * @param string $username Username to delete
     *
     * @return bool True on success
     *
     * @throws RuntimeException If user doesn't exist
     */
    public function deleteUser(string $username): bool
    {
        if (!$this->cliMode && !$this->hasRole('admin')) {
            throw new RuntimeException('Unauthorized: Admin access required');
        }
        $this->updateUsers(function () use ($username): bool {
            $this->assertUserExists($username);
            unset($this->users[$username]);

            return true;
        });
        $this->logger->info("Deleted user: {$username}");

        return true;
    }

    /**
     * Change user password.
     *
     * @param string $username    Username
     * @param string $newPassword New plain text password
     *
     * @return bool True on success
     *
     * @throws RuntimeException If user doesn't exist
     */
    public function changePassword(string $username, string $newPassword): bool
    {
        if (!$this->cliMode && !$this->hasRole('admin')) {
            throw new RuntimeException('Unauthorized: Admin access required');
        }
        $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);

        $this->updateUsers(function () use ($username, $passwordHash): bool {
            $this->assertUserExists($username);
            $this->users[$username]['password_hash'] = $passwordHash;
            $this->users[$username]['failed_attempts'] = 0;
            unset($this->users[$username]['locked_until']);
            $this->rotateAuthRevision($username);

            return true;
        });
        $this->logger->info("Password changed for user: {$username}");

        return true;
    }

    /**
     * Update user role.
     *
     * @param string $username Username
     * @param string $role     New role
     *
     * @return bool True on success
     *
     * @throws RuntimeException If user doesn't exist
     */
    public function updateUserRole(string $username, string $role): bool
    {
        if (!$this->cliMode && !$this->hasRole('admin')) {
            throw new RuntimeException('Unauthorized: Admin access required');
        }
        $this->assertValidRole($role);

        $this->updateUsers(function () use ($username, $role): bool {
            $this->assertUserExists($username);
            $this->users[$username]['role'] = $role;
            $this->rotateAuthRevision($username);

            return true;
        });
        $this->logger->info("Updated role for user {$username} to: {$role}");

        return true;
    }

    /**
     * Get all users (without password hashes).
     *
     * @return array<string,array<string,mixed>> Array of user data
     */
    public function getAllUsers(): array
    {
        if (!$this->cliMode && !$this->hasRole('admin')) {
            throw new RuntimeException('Unauthorized: Admin access required');
        }
        $users = [];
        foreach ($this->users as $username => $user) {
            $users[$username] = [
                'id' => $user['id'],
                'username' => $username,
                'role' => $user['role'] ?? 'viewer',
                'created_at' => $user['created_at'] ?? 'unknown',
                'failed_attempts' => $user['failed_attempts'] ?? 0,
                'locked_until' => $user['locked_until'] ?? null,
            ];
        }

        return $users;
    }

    /**
     * Check if user exists in the system.
     *
     * @param string $username Username to check
     *
     * @return bool True if user exists, false otherwise
     */
    public function userExists(string $username): bool
    {
        return isset($this->users[$username]);
    }

    /**
     * Unlock a locked user account.
     *
     * @param string $username Username to unlock
     *
     * @return bool True on success
     *
     * @throws RuntimeException If user doesn't exist
     */
    public function unlockUser(string $username): bool
    {
        if (!$this->cliMode && !$this->hasRole('admin')) {
            throw new RuntimeException('Unauthorized: Admin access required');
        }
        $this->updateUsers(function () use ($username): bool {
            $this->assertUserExists($username);
            $this->users[$username]['failed_attempts'] = 0;
            unset($this->users[$username]['locked_until']);

            return true;
        });
        $this->logger->info("Unlocked user account: {$username}");

        return true;
    }

    /**
     * Get user information (without password hash).
     *
     * @param string $username Username
     *
     * @return null|array<string,mixed> User data or null if not found
     */
    public function getUserInfo(string $username): ?array
    {
        if (!$this->cliMode && !$this->hasRole('admin')) {
            throw new RuntimeException('Unauthorized: Admin access required');
        }
        $user = $this->users[$username] ?? null;
        if (!$user) {
            return null;
        }

        return [
            'id' => $user['id'],
            'username' => $username,
            'role' => $user['role'] ?? 'viewer',
            'created_at' => $user['created_at'] ?? 'unknown',
            'failed_attempts' => $user['failed_attempts'] ?? 0,
            'locked_until' => $user['locked_until'] ?? null,
        ];
    }
}
