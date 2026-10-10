<?php

declare(strict_types=1);

namespace Mata\Tests\Helpers;

/**
 * Writes and inspects users.json files the way an operator or another request would, bypassing UserRepository.
 */
final class UsersFileFactory
{
    /**
     * @param array<string,mixed> $overrides
     *
     * @return array<string,mixed>
     */
    public static function record(string $username, string $password, string $role = 'viewer', array $overrides = []): array
    {
        return [
            'id' => 'user_' . $username,
            'username' => $username,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'auth_revision' => bin2hex(random_bytes(32)),
            'role' => $role,
            'created_at' => '2026-01-01 00:00:00',
            'failed_attempts' => 0,
            ...$overrides,
        ];
    }

    /** @param list<array<string,mixed>> $records */
    public static function write(string $path, array $records, int $mode = 0600): void
    {
        file_put_contents($path, json_encode($records, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        chmod($path, $mode);
    }

    /** @return null|array<string,mixed> */
    public static function find(string $path, string $username): ?array
    {
        $records = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        return array_column($records, null, 'username')[$username] ?? null;
    }

    /** @param callable(array<string,mixed>): array<string,mixed> $change */
    public static function change(string $path, string $username, callable $change): void
    {
        $records = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        foreach ($records as $index => $record) {
            if ($record['username'] === $username) {
                $records[$index] = $change($record);
            }
        }
        self::write($path, $records);
    }
}
