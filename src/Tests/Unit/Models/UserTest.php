<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Models;

use Mata\Models\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(User::class)]
class UserTest extends TestCase
{
    public function testItRoundTripsAStoredRecord(): void
    {
        $record = [
            'id' => 'user_1',
            'username' => 'admin',
            'password_hash' => '$2y$12$hash',
            'auth_revision' => 'revision',
            'role' => 'admin',
            'created_at' => '2026-01-01 00:00:00',
            'failed_attempts' => 2,
            'locked_until' => 1000,
            'remember_token' => 'token-hash',
            'remember_expires' => 2000,
        ];

        $this->assertSame($record, User::fromArray($record)->toArray());
    }

    public function testItLoadsMinimalRecordsWithDefaults(): void
    {
        $user = User::fromArray(['id' => 'user_1', 'username' => 'admin', 'password_hash' => 'hash', 'role' => 'viewer']);

        $this->assertNull($user->authRevision);
        $this->assertSame(0, $user->failedAttempts);
        $this->assertFalse($user->isLocked(time()));
        $this->assertSame(
            ['id' => 'user_1', 'username' => 'admin', 'password_hash' => 'hash', 'role' => 'viewer', 'failed_attempts' => 0],
            $user->toArray(),
        );
    }

    public function testCreateGeneratesIdentifiersAndARevision(): void
    {
        $first = User::create('admin', 'hash', 'admin');
        $second = User::create('admin', 'hash', 'admin');

        $this->assertMatchesRegularExpression('/^user_[0-9a-f]{16}$/', $first->id);
        $this->assertNotSame($first->id, $second->id);
        $this->assertNotSame($first->authRevision, $second->authRevision);
    }

    public function testLockoutEndsAtLockedUntil(): void
    {
        $user = self::user()->withFailedAttempts(5, 1000);

        $this->assertTrue($user->isLocked(999));
        $this->assertFalse($user->isLocked(1000));
        $this->assertFalse($user->withoutLockout()->isLocked(999));
        $this->assertSame(0, $user->withoutLockout()->failedAttempts);
    }

    public function testRememberTokensAreStoredHashedAndExpire(): void
    {
        $user = self::user()->withRememberToken('secret', 1000);

        $this->assertSame(hash('sha256', 'secret'), $user->rememberTokenHash);
        $this->assertTrue($user->hasValidRememberToken('secret', 999));
        $this->assertFalse($user->hasValidRememberToken('secret', 1000));
        $this->assertFalse($user->hasValidRememberToken('other', 999));
        $this->assertFalse($user->withoutRememberToken()->hasValidRememberToken('secret', 999));
    }

    public function testRevokingSessionsRotatesTheRevisionAndDropsTheRememberToken(): void
    {
        $user = self::user()->withRememberToken('secret', 1000);

        $revoked = $user->withSessionsRevoked();

        $this->assertNotSame($user->authRevision, $revoked->authRevision);
        $this->assertNull($revoked->rememberTokenHash);
        $this->assertNull($revoked->rememberExpires);
    }

    public function testBackfillKeepsAnExistingRevision(): void
    {
        $user = self::user();

        $this->assertSame($user, $user->withBackfilledAuthRevision());
        $this->assertNotNull(User::fromArray([...$user->toArray(), 'auth_revision' => null])->withBackfilledAuthRevision()->authRevision);
    }

    private static function user(): User
    {
        return User::create('admin', 'hash', 'admin');
    }
}
