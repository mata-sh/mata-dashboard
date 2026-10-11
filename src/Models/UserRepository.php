<?php

declare(strict_types=1);

namespace Mata\Models;

use InvalidArgumentException;
use JsonException;
use RuntimeException;

/**
 * Accounts stored in users.json.
 *
 * Writes go through update(), which re-reads the file under an exclusive lock and replaces it atomically,
 * so concurrent requests never overwrite each other's changes. Reads take no lock: the atomic replace means
 * a reader always sees a complete file, and any snapshot is stale once loaded anyway, so a change that
 * depends on current state must re-check it inside update().
 */
final class UserRepository
{
    /** @var array<string,User> */
    private array $users = [];

    private function __construct(private readonly string $usersFile) {}

    /**
     * Load a users file that must exist and be private to its owner.
     *
     * @throws RuntimeException If the file is missing, unreadable, invalid or readable by group or others
     */
    public static function open(string $usersFile): self
    {
        if (!file_exists($usersFile)) {
            throw new RuntimeException("Users file not found: {$usersFile}");
        }
        $perms = fileperms($usersFile);
        if ($perms !== false && ($perms & 0o077) !== 0) {
            throw new RuntimeException("Users file has insecure permissions. Run: chmod 600 {$usersFile}");
        }

        $repository = new self($usersFile);
        $repository->users = $repository->read();

        return $repository;
    }

    /**
     * Load the users file for administration: a missing file starts empty and loose permissions are
     * tolerated, because the next write creates or repairs the file with mode 0600.
     *
     * @throws RuntimeException If the file exists but is unreadable or invalid
     */
    public static function openForAdministration(string $usersFile): self
    {
        $repository = new self($usersFile);
        if (file_exists($usersFile)) {
            $repository->users = $repository->read();
        }

        return $repository;
    }

    public function find(string $username): ?User
    {
        return $this->users[$username] ?? null;
    }

    /** @return array<string,User> Users indexed by username */
    public function all(): array
    {
        return $this->users;
    }

    /**
     * Apply a change to the latest users and save the result.
     *
     * The change re-checks its own preconditions against the fresh users and returns null to skip the write.
     *
     * @param callable(array<string,User>): ?array<string,User> $change
     *
     * @return bool Whether the change was saved
     *
     * @throws RuntimeException If the users file cannot be read, locked or written
     */
    public function update(callable $change): bool
    {
        $directory = dirname($this->usersFile);
        if (!is_dir($directory) || !is_writable($directory)) {
            throw new RuntimeException(
                "Users directory is not writable: {$directory}. "
                . 'The PHP process needs write access to atomically update users.json.'
            );
        }

        return $this->withExclusiveLock(function () use ($change): bool {
            $this->users = file_exists($this->usersFile) ? $this->read() : [];

            $changed = $change($this->users);
            if ($changed === null) {
                return false;
            }

            $this->write($changed);
            $this->users = $changed;

            return true;
        });
    }

    /**
     * Apply a change to one user; see update().
     *
     * @param callable(User): ?User $change
     *
     * @return bool False if the user does not exist or the change returned null
     */
    public function updateUser(string $username, callable $change): bool
    {
        return $this->update(static function (array $users) use ($username, $change): ?array {
            $user = isset($users[$username]) ? $change($users[$username]) : null;
            if ($user === null) {
                return null;
            }
            $users[$username] = $user;

            return $users;
        });
    }

    /**
     * @return array<string,User>
     *
     * @throws RuntimeException If the file is unreadable or not a list of valid user records
     */
    private function read(): array
    {
        if (!is_readable($this->usersFile)) {
            throw new RuntimeException("Users file not readable: {$this->usersFile}");
        }

        $content = file_get_contents($this->usersFile);
        if ($content === false) {
            throw new RuntimeException("Failed to read users file: {$this->usersFile}");
        }

        try {
            $records = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Invalid JSON in users file: ' . $e->getMessage(), 0, $e);
        }
        if (!is_array($records) || !array_is_list($records)) {
            throw new RuntimeException('Invalid users file: expected a list of users');
        }

        $users = [];
        foreach ($records as $index => $record) {
            if (!is_array($record)) {
                throw new RuntimeException("Invalid users file: entry {$index}: expected an object");
            }

            try {
                $user = User::fromArray($record);
            } catch (InvalidArgumentException $e) {
                throw new RuntimeException("Invalid users file: entry {$index}: {$e->getMessage()}", 0, $e);
            }
            $users[$user->username] = $user;
        }

        return $users;
    }

    /**
     * Atomically replace the users file with mode 0600. The caller holds the exclusive lock.
     *
     * @param array<string,User> $users
     */
    private function write(array $users): void
    {
        $records = array_map(static fn (User $user): array => $user->toArray(), array_values($users));
        $encoded = json_encode($records, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $directory = dirname($this->usersFile);
        $temp = tempnam($directory, basename($this->usersFile) . '.');
        // tempnam() silently falls back to the system temp directory, where rename() would not be atomic.
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
    }

    /**
     * Run a callback while holding an exclusive lock on the users file's companion lock file.
     *
     * Locks are not reentrant: flock() on a second handle in the same process blocks.
     *
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    private function withExclusiveLock(callable $callback): mixed
    {
        $lockFile = $this->usersFile . '.lock';
        $created = !file_exists($lockFile);
        $handle = fopen($lockFile, 'c');
        if ($handle === false) {
            throw new RuntimeException("Failed to open users lock file: {$lockFile}");
        }

        try {
            // Only the owner may chmod, so an existing lock file shared with another account is left as is.
            if ($created && !chmod($lockFile, 0600)) {
                throw new RuntimeException("Failed to secure users lock file: {$lockFile}");
            }
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException("Failed to lock users file: {$this->usersFile}");
            }

            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
