<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Models\Auth;

use Mata\Models\Auth\User;
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
            'role' => 'admin',
            'auth_revision' => 'revision',
            'created_at' => '2026-01-01 00:00:00',
            'failed_attempts' => 2,
            'locked_until' => 1000,
            'remember_token_hash' => 'token-hash',
            'remember_expires' => 2000,
        ];

        $this->assertSame($record, User::fromArray($record)->toArray());
    }

    public function testItLoadsMinimalRecordsWithDefaults(): void
    {
        $record = ['id' => 'user_1', 'username' => 'admin', 'password_hash' => 'hash', 'role' => 'viewer', 'auth_revision' => 'revision'];
        $user = User::fromArray($record);

        $this->assertSame(0, $user->failedAttempts);
        $this->assertFalse($user->isLocked(time()));
        $this->assertSame([...$record, 'failed_attempts' => 0], $user->toArray());
    }

    public function testCreateGeneratesIdentifiersAndARevision(): void
    {
        $first = User::create('admin', 'hash', 'admin');
        $second = User::create('admin', 'hash', 'admin');

        $this->assertMatchesRegularExpression('/^user_[0-9a-f]{16}$/', $first->id);
        $this->assertNotSame($first->id, $second->id);
        $this->assertNotSame($first->authRevision, $second->authRevision);
    }

    public function testFailedAttemptsLockTheAccountUntilTheLockoutEnds(): void
    {
        $user = self::user()->withFailedAttempt(100, 2, 900);
        $this->assertSame(1, $user->failedAttempts);
        $this->assertFalse($user->isLocked(100));

        $locked = $user->withFailedAttempt(100, 2, 900);
        $this->assertTrue($locked->isLocked(999));
        $this->assertFalse($locked->isLocked(1000));
        $this->assertFalse($locked->withoutLockout()->isLocked(999));
        $this->assertSame(0, $locked->withoutLockout()->failedAttempts);
    }

    public function testAFailureAfterAnExpiredLockoutStartsAFreshCount(): void
    {
        $user = self::user()->withFailedAttempt(100, 1, 900)->withFailedAttempt(1000, 2, 900);

        $this->assertSame(1, $user->failedAttempts);
        $this->assertFalse($user->isLocked(1000));
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

    private static function user(): User
    {
        return User::create('admin', 'hash', 'admin');
    }
}
