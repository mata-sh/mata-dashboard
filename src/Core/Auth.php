<?php

declare(strict_types=1);

namespace Mata\Core;

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
    private ?int $usersFileMtime = null;

    /** @var array<string,array<string,mixed>> */
    private array $users = [];
    private const MAX_ATTEMPTS = 5;
    private const LOCKOUT_TIME_SECONDS = 900; // 15 minutes

    /** @var bool CLI mode bypasses role checks for user management */
    private bool $cliMode;
    private LoggerInterface $logger;

    /**
     * Initialize authentication system.
     *
     * @param LoggerInterface $logger    Logger instance for audit logging
     * @param string          $usersFile Absolute path to users.json file
     * @param bool            $cliMode   Enable CLI mode (bypasses role checks, default: false)
     *
     * @throws RuntimeException If users file is missing or has insecure permissions
     */
    public function __construct(LoggerInterface $logger, string $usersFile, bool $cliMode = false)
    {
        $this->usersFile = $usersFile;
        $this->logger = $logger;
        $this->cliMode = $cliMode;

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
            if (!$this->cliMode && (($perms & 0x0040) || ($perms & 0x0004))) {
                throw new RuntimeException("Users file has insecure permissions. Run: chmod 600 {$this->usersFile}");
            }

            if (!is_readable($this->usersFile)) {
                if ($this->cliMode) {
                    throw new RuntimeException(
                        "Users file not readable: {$this->usersFile}\n"
                        . 'Try running with: sudo -u ' . posix_getpwuid(fileowner($this->usersFile))['name'] . ' php ' . $_SERVER['argv'][0]
                    );
                }

                throw new RuntimeException("Users file not readable: {$this->usersFile}");
            }

            $content = file_get_contents($this->usersFile);
            if ($content === false) {
                throw new RuntimeException("Failed to read users file: {$this->usersFile}");
            }

            $mtime = filemtime($this->usersFile);
            if ($mtime === false) {
                throw new RuntimeException("Failed to stat users file: {$this->usersFile}");
            }

            $users = json_decode($content, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new RuntimeException('Invalid JSON in users file: ' . json_last_error_msg());
            }

            // Index by username
            $this->users = [];
            foreach ($users as $user) {
                if (isset($user['username'])) {
                    $this->users[$user['username']] = $user;
                }
            }

            $this->usersFileMtime = $mtime;
            $this->logger->info('Loaded users file');
        });
    }

    /**
     * Authenticate user and create session.
     *
     * @param string $username Username to authenticate
     * @param string $password Plain text password
     * @param bool   $remember Whether to create remember-me cookie (default: false)
     *
     * @return bool True on successful login, false on failure
     */
    public function login(string $username, string $password, bool $remember = false): bool
    {
        $user = $this->users[$username] ?? null;

        if (!$user) {
            // Prevent timing attacks
            password_verify('dummy', '$2y$10$dummy');
            $this->logger->warning("Login attempt for non-existent user: {$username}");

            return false;
        }

        // Check if account is locked
        if (isset($user['locked_until']) && time() < $user['locked_until']) {
            $this->logger->warning("Login attempt on locked account: {$username}");

            return false;
        }

        // Verify password
        if (!password_verify($password, $user['password_hash'])) {
            $this->recordFailedAttempt($username);
            $this->logger->warning("Failed login for user: {$username}");

            return false;
        }

        // Rehash password (if algorithm upgrade)
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            $this->users[$username]['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
            $this->saveUsers();
            $this->logger->info("Password rehashed for user: {$username}");
        }

        // Successful login
        $_SESSION['user'] = [
            'username' => $username,
            'role' => $user['role'] ?? 'viewer',
            'id' => $user['id'] ?? uniqid('usr_'),
        ];
        $_SESSION['logged_in'] = true;
        Session::regenerate();

        // Clear failed attempts
        if (isset($this->users[$username]['failed_attempts'])) {
            $this->users[$username]['failed_attempts'] = 0;
            unset($this->users[$username]['locked_until']);
            $this->saveUsers();
        }

        if ($remember) {
            $this->setRememberMe($username);
        }

        $this->logger->info("Successful login: {$username}");

        return true;
    }

    /**
     * Set secure remember-me cookie for user.
     *
     * @param string $username Username to remember
     */
    private function setRememberMe(string $username): void
    {
        // Generate secure token
        $token = bin2hex(random_bytes(32));
        $hashedToken = hash('sha256', $token);

        // Store hashed token with user
        $this->users[$username]['remember_token'] = $hashedToken;
        $this->users[$username]['remember_expires'] = time() + (30 * 24 * 60 * 60);
        $this->saveUsers();

        // Set cookie with unhashed token
        setcookie(
            'remember_token',
            $username . '|' . $token,
            [
                'expires' => time() + (30 * 24 * 60 * 60),
                'path' => '/',
                'secure' => Session::isSecureRequest(),
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
        if ($this->check() || !isset($_COOKIE['remember_token'])) {
            return;
        }

        [$username, $token] = explode('|', $_COOKIE['remember_token'] . '|');
        $user = $this->users[$username] ?? null;

        if (!$user || !isset($user['remember_token'])) {
            return;
        }

        // Verify token and expiry
        if (
            hash_equals($user['remember_token'], hash('sha256', $token))
            && time() < $user['remember_expires']
        ) {
            $_SESSION['user'] = [
                'username' => $username,
                'role' => $user['role'] ?? 'viewer',
                'id' => $user['id'] ?? uniqid('usr_'),
            ];
            $_SESSION['logged_in'] = true;
            Session::regenerate();

            // Regenerate token for security
            $this->setRememberMe($username);

            $this->logger->info("Auto-login via remember token: {$username}");
        }
    }

    /**
     * Record failed login attempt and implement account lockout.
     *
     * @param string $username Username that failed authentication
     */
    private function recordFailedAttempt(string $username): void
    {
        if (!isset($this->users[$username])) {
            return;
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

        $this->saveUsers();
    }

    /**
     * Atomically save users array to JSON file with secure permissions.
     */
    private function saveUsers(): void
    {
        $this->assertUsersDirectoryWritable();

        $this->withFileLock(LOCK_EX, function () {
            if (file_exists($this->usersFile)) {
                $currentMtime = filemtime($this->usersFile);
                if ($currentMtime === false) {
                    throw new RuntimeException("Failed to stat users file: {$this->usersFile}");
                }
                if ($this->usersFileMtime !== null && $currentMtime !== $this->usersFileMtime) {
                    throw new RuntimeException('Users file changed on disk; reload before saving');
                }
            }

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
            $savedMtime = filemtime($this->usersFile);
            if ($savedMtime !== false) {
                $this->usersFileMtime = $savedMtime;
            }
        });
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
     * @param int      $lockType Lock type (LOCK_SH for read, LOCK_EX for write)
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

        // Clear remember token
        if ($username && isset($this->users[$username]['remember_token'])) {
            unset($this->users[$username]['remember_token'], $this->users[$username]['remember_expires']);

            $this->saveUsers();
        }

        Session::destroy();
        setcookie('remember_token', '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => Session::isSecureRequest(),
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
        return isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
    }

    /**
     * Get current authenticated user data.
     *
     * @return null|array<string,mixed> User data array or null if not logged in
     */
    public function getUser(): ?array
    {
        return $_SESSION['user'] ?? null;
    }

    /**
     * Get current authenticated username.
     *
     * @return null|string Username or null if not logged in
     */
    public function getUsername(): ?string
    {
        return $_SESSION['user']['username'] ?? null;
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
        return ($_SESSION['user']['role'] ?? '') === $role;
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
        if (isset($this->users[$username])) {
            throw new RuntimeException("User already exists: {$username}");
        }

        $this->users[$username] = [
            'id' => uniqid('user_'),
            'username' => $username,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role' => $role,
            'created_at' => date('Y-m-d H:i:s'),
            'failed_attempts' => 0,
        ];

        $this->saveUsers();
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
        if (!isset($this->users[$username])) {
            throw new RuntimeException("User does not exist: {$username}");
        }

        unset($this->users[$username]);
        $this->saveUsers();
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
        if (!isset($this->users[$username])) {
            throw new RuntimeException("User does not exist: {$username}");
        }

        $this->users[$username]['password_hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
        $this->users[$username]['failed_attempts'] = 0; // Reset failed attempts
        unset($this->users[$username]['locked_until']); // Remove any lockout

        $this->saveUsers();
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
        if (!isset($this->users[$username])) {
            throw new RuntimeException("User does not exist: {$username}");
        }

        $this->users[$username]['role'] = $role;
        $this->saveUsers();
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
        if (!isset($this->users[$username])) {
            throw new RuntimeException("User does not exist: {$username}");
        }

        $this->users[$username]['failed_attempts'] = 0;
        unset($this->users[$username]['locked_until']);

        $this->saveUsers();
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
