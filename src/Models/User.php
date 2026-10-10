<?php

declare(strict_types=1);

namespace Mata\Models;

use InvalidArgumentException;

/**
 * One account from users.json.
 *
 * `authRevision` is copied into the session at sign-in. Rotating it signs the account out everywhere.
 */
final readonly class User
{
    public function __construct(
        public string $id,
        public string $username,
        public string $passwordHash,
        public string $role,
        public ?string $authRevision = null,
        public ?string $createdAt = null,
        public int $failedAttempts = 0,
        public ?int $lockedUntil = null,
        public ?string $rememberTokenHash = null,
        public ?int $rememberExpires = null,
    ) {}

    public static function create(string $username, string $passwordHash, string $role): self
    {
        return new self(
            id: 'user_' . bin2hex(random_bytes(8)),
            username: $username,
            passwordHash: $passwordHash,
            role: $role,
            authRevision: self::newAuthRevision(),
            createdAt: date('Y-m-d H:i:s'),
        );
    }

    /**
     * @param array<mixed> $record
     *
     * @throws InvalidArgumentException If a field is missing or has the wrong JSON type
     */
    public static function fromArray(array $record): self
    {
        return new self(
            id: self::requiredString($record, 'id'),
            username: self::requiredString($record, 'username'),
            passwordHash: self::requiredString($record, 'password_hash'),
            role: self::requiredString($record, 'role'),
            authRevision: self::optionalString($record, 'auth_revision'),
            createdAt: self::optionalString($record, 'created_at'),
            failedAttempts: self::optionalInt($record, 'failed_attempts') ?? 0,
            lockedUntil: self::optionalInt($record, 'locked_until'),
            rememberTokenHash: self::optionalString($record, 'remember_token'),
            rememberExpires: self::optionalInt($record, 'remember_expires'),
        );
    }

    /** @return array<string,int|string> The users.json record, without empty optional fields */
    public function toArray(): array
    {
        return array_filter([
            'id' => $this->id,
            'username' => $this->username,
            'password_hash' => $this->passwordHash,
            'auth_revision' => $this->authRevision,
            'role' => $this->role,
            'created_at' => $this->createdAt,
            'failed_attempts' => $this->failedAttempts,
            'locked_until' => $this->lockedUntil,
            'remember_token' => $this->rememberTokenHash,
            'remember_expires' => $this->rememberExpires,
        ], static fn (int|string|null $value): bool => $value !== null);
    }

    public function isLocked(int $now): bool
    {
        return $this->lockedUntil !== null && $now < $this->lockedUntil;
    }

    public function hasValidRememberToken(string $token, int $now): bool
    {
        return $this->rememberTokenHash !== null
            && $this->rememberExpires !== null
            && hash_equals($this->rememberTokenHash, hash('sha256', $token))
            && $now < $this->rememberExpires;
    }

    public function withPasswordHash(string $passwordHash): self
    {
        return $this->with(passwordHash: $passwordHash);
    }

    public function withRole(string $role): self
    {
        return $this->with(role: $role);
    }

    public function withFailedAttempts(int $failedAttempts, ?int $lockedUntil): self
    {
        return $this->with(failedAttempts: $failedAttempts, lockedUntil: $lockedUntil);
    }

    public function withoutLockout(): self
    {
        return $this->withFailedAttempts(0, null);
    }

    /** Stores only the token's hash, so a leaked users.json cannot be replayed as a cookie. */
    public function withRememberToken(string $token, int $expires): self
    {
        return $this->with(rememberTokenHash: hash('sha256', $token), rememberExpires: $expires);
    }

    public function withoutRememberToken(): self
    {
        return $this->with(rememberTokenHash: null, rememberExpires: null);
    }

    /** Accounts created before revision-based sessions get a revision on their next sign-in. */
    public function withBackfilledAuthRevision(): self
    {
        return $this->authRevision === null ? $this->with(authRevision: self::newAuthRevision()) : $this;
    }

    /** Invalidate every existing session and remember-me cookie for this account. */
    public function withSessionsRevoked(): self
    {
        return $this->with(authRevision: self::newAuthRevision())->withoutRememberToken();
    }

    private function with(mixed ...$changes): self
    {
        return new self(...[...get_object_vars($this), ...$changes]);
    }

    private static function newAuthRevision(): string
    {
        return bin2hex(random_bytes(32));
    }

    /** @param array<mixed> $record */
    private static function requiredString(array $record, string $key): string
    {
        $value = $record[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new InvalidArgumentException("\"{$key}\" must be a non-empty string");
        }

        return $value;
    }

    /** @param array<mixed> $record */
    private static function optionalString(array $record, string $key): ?string
    {
        $value = $record[$key] ?? null;
        if ($value !== null && !is_string($value)) {
            throw new InvalidArgumentException("\"{$key}\" must be a string");
        }

        return $value;
    }

    /** @param array<mixed> $record */
    private static function optionalInt(array $record, string $key): ?int
    {
        $value = $record[$key] ?? null;
        if ($value !== null && !is_int($value)) {
            throw new InvalidArgumentException("\"{$key}\" must be an integer");
        }

        return $value;
    }
}
