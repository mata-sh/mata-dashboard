<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services\Auth;

use InvalidArgumentException;
use Mata\Models\UserRepository;
use Mata\Services\Auth\UserAdministrator;
use Mata\Tests\Helpers\UsersFileFactory;
use Mata\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @internal
 */
#[CoversClass(UserAdministrator::class)]
class UserAdministratorTest extends TestCase
{
    private string $usersFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->usersFile = $this->createTempFile('', '.json');
        UsersFileFactory::write($this->usersFile, [UsersFileFactory::record('admin', 'admin123', 'admin')]);
    }

    protected function tearDown(): void
    {
        $this->cleanupTempFiles([$this->usersFile, $this->usersFile . '.lock']);
        parent::tearDown();
    }

    public function testItCreatesUsersWithHashedPasswords(): void
    {
        $this->administrator()->createUser('bob', 'plaintext_password', 'viewer');

        $bob = $this->stored('bob');
        $this->assertSame('viewer', $bob['role']);
        $this->assertSame(0, $bob['failed_attempts']);
        $this->assertNotEmpty($bob['auth_revision']);
        $this->assertStringStartsWith('$2y$', $bob['password_hash']);
        $this->assertTrue(password_verify('plaintext_password', $bob['password_hash']));
    }

    public function testItCreatesTheUsersFileWhenItIsMissing(): void
    {
        unlink($this->usersFile);

        $this->administrator()->createUser('first', 'password', 'admin');

        $this->assertSame('admin', $this->stored('first')['role']);
        $this->assertSame(0o600, fileperms($this->usersFile) & 0o777);
    }

    public function testItRejectsDuplicateUsers(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('User already exists');

        $this->administrator()->createUser('admin', 'different_password');
    }

    #[DataProvider('invalidUsernames')]
    public function testItRejectsInvalidUsernames(string $username): void
    {
        $administrator = $this->administrator();

        try {
            $administrator->createUser($username, 'password123');
            $this->fail('Invalid username was accepted');
        } catch (InvalidArgumentException) {
            $this->assertArrayNotHasKey($username, $administrator->listUsers());
        }
    }

    /** @return array<string,array{string}> */
    public static function invalidUsernames(): array
    {
        return [
            'empty' => [''],
            'space' => ['bob smith'],
            'newline' => ["bob\nFailed login for user: admin"],
            'path' => ['../bob'],
            'too long' => [str_repeat('a', 65)],
        ];
    }

    #[DataProvider('invalidRoles')]
    public function testItRejectsUnknownRoles(string $role): void
    {
        $administrator = $this->administrator();

        try {
            $administrator->createUser('newuser', 'password', $role);
            $this->fail('Unknown role was accepted on create');
        } catch (InvalidArgumentException) {
            $this->assertArrayNotHasKey('newuser', $administrator->listUsers());
        }

        try {
            $administrator->changeRole('admin', $role);
            $this->fail('Unknown role was accepted on change');
        } catch (InvalidArgumentException) {
            $this->assertSame('admin', $this->stored('admin')['role']);
        }
    }

    /** @return array<string,array{string}> */
    public static function invalidRoles(): array
    {
        return [
            'typo' => ['admn'],
            'empty' => [''],
            'wrong case' => ['Admin'],
        ];
    }

    public function testItDeletesUsers(): void
    {
        $administrator = $this->administrator();
        $administrator->createUser('to_delete', 'password123');

        $administrator->deleteUser('to_delete');

        $this->assertNull(UsersFileFactory::find($this->usersFile, 'to_delete'));
        $this->assertArrayNotHasKey('to_delete', $administrator->listUsers());
    }

    public function testItListsUsersByUsername(): void
    {
        $administrator = $this->administrator();
        $administrator->createUser('user1', 'pass1', 'viewer');
        $administrator->createUser('user2', 'pass2', 'admin');

        $users = $administrator->listUsers();

        $this->assertSame(['admin', 'user1', 'user2'], array_keys($users));
        $this->assertSame('admin', $users['user2']->role);
    }

    public function testChangingThePasswordClearsLockoutAndRevokesSessions(): void
    {
        $this->lock('admin');
        $before = $this->stored('admin');

        $this->administrator()->changePassword('admin', 'replacement');

        $after = $this->stored('admin');
        $this->assertTrue(password_verify('replacement', $after['password_hash']));
        $this->assertSame(0, $after['failed_attempts']);
        $this->assertArrayNotHasKey('locked_until', $after);
        $this->assertArrayNotHasKey('remember_token', $after);
        $this->assertNotSame($before['auth_revision'], $after['auth_revision']);
    }

    public function testChangingTheRoleRevokesSessions(): void
    {
        $before = $this->stored('admin');

        $this->administrator()->changeRole('admin', 'viewer');

        $after = $this->stored('admin');
        $this->assertSame('viewer', $after['role']);
        $this->assertNotSame($before['auth_revision'], $after['auth_revision']);
    }

    public function testUnlockingClearsTheLockoutButKeepsSessions(): void
    {
        $this->lock('admin');
        $before = $this->stored('admin');

        $this->administrator()->unlockUser('admin');

        $after = $this->stored('admin');
        $this->assertSame(0, $after['failed_attempts']);
        $this->assertArrayNotHasKey('locked_until', $after);
        $this->assertSame($before['auth_revision'], $after['auth_revision']);
    }

    /** @param callable(UserAdministrator): void $change */
    #[DataProvider('changesToExistingUsers')]
    public function testItRejectsChangesToUnknownUsers(callable $change): void
    {
        $before = file_get_contents($this->usersFile);

        try {
            $change($this->administrator());
            $this->fail('A change to an unknown user was accepted');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('User does not exist: nobody', $e->getMessage());
        }
        $this->assertSame($before, file_get_contents($this->usersFile));
    }

    /** @return array<string,array{callable(UserAdministrator): void}> */
    public static function changesToExistingUsers(): array
    {
        return [
            'delete' => [static fn (UserAdministrator $a) => $a->deleteUser('nobody')],
            'password' => [static fn (UserAdministrator $a) => $a->changePassword('nobody', 'password')],
            'role' => [static fn (UserAdministrator $a) => $a->changeRole('nobody', 'admin')],
            'unlock' => [static fn (UserAdministrator $a) => $a->unlockUser('nobody')],
        ];
    }

    public function testConcurrentAdministratorsKeepEachOthersChanges(): void
    {
        unlink($this->usersFile);
        $first = $this->administrator();
        $second = $this->administrator();

        $first->createUser('first', 'password');
        $second->createUser('second', 'password');

        $this->assertNotNull(UsersFileFactory::find($this->usersFile, 'first'), 'A competing bootstrap must not replace the first users file');
        $this->assertNotNull(UsersFileFactory::find($this->usersFile, 'second'));
    }

    public function testAStaleAdministratorCannotRestoreRevokedCredentials(): void
    {
        $stale = $this->administrator();
        $this->administrator()->changePassword('admin', 'replacement');

        $stale->createUser('another', 'password');

        $this->assertTrue(password_verify('replacement', $this->stored('admin')['password_hash']));
    }

    public function testAStaleAdministratorCannotRecreateADeletedUser(): void
    {
        $stale = $this->administrator();
        unlink($this->usersFile);

        try {
            $stale->changePassword('admin', 'replacement');
            $this->fail('A stale administrator must not recreate a deleted user');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('User does not exist', $e->getMessage());
        }
        $this->assertFileDoesNotExist($this->usersFile);
    }

    private function administrator(): UserAdministrator
    {
        return new UserAdministrator(UserRepository::openForAdministration($this->usersFile), new NullLogger());
    }

    private function lock(string $username): void
    {
        UsersFileFactory::change($this->usersFile, $username, static fn (array $user): array => [
            ...$user,
            'failed_attempts' => 5,
            'locked_until' => time() + 900,
            'remember_token' => hash('sha256', 'token'),
            'remember_expires' => time() + 3600,
        ]);
    }

    /** @return array<string,mixed> */
    private function stored(string $username): array
    {
        return UsersFileFactory::find($this->usersFile, $username) ?? $this->fail("User {$username} is not stored");
    }
}
