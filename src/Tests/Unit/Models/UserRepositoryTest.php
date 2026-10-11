<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Models;

use Mata\Models\User;
use Mata\Models\UserRepository;
use Mata\Tests\Helpers\UsersFileFactory;
use Mata\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * @internal
 */
#[CoversClass(UserRepository::class)]
class UserRepositoryTest extends TestCase
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

    public function testItLoadsUsersByUsername(): void
    {
        $users = UserRepository::open($this->usersFile);

        $this->assertSame(['admin'], array_keys($users->all()));
        $this->assertSame('admin', $users->find('admin')?->role);
        $this->assertNull($users->find('nobody'));
    }

    public function testItRequiresTheUsersFile(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Users file not found');

        UserRepository::open('/nonexistent/users.json');
    }

    #[DataProvider('insecureModes')]
    public function testItRejectsFilesReadableOrWritableByOthers(int $mode): void
    {
        chmod($this->usersFile, $mode);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('insecure permissions');

        UserRepository::open($this->usersFile);
    }

    /** @return array<string,array{int}> */
    public static function insecureModes(): array
    {
        return [
            'world-readable' => [0o644],
            'other-readable only' => [0o604],
            'group-readable' => [0o640],
            'group-writable' => [0o620],
        ];
    }

    /** Owner-only bits are not an exposure, including the execute bit. */
    public function testItAcceptsOwnerOnlyPermissions(): void
    {
        chmod($this->usersFile, 0o700);

        $this->assertNotNull(UserRepository::open($this->usersFile)->find('admin'));
    }

    public function testAdministrationToleratesAMissingOrInsecureFile(): void
    {
        $this->assertSame([], UserRepository::openForAdministration('/nonexistent/users.json')->all());

        chmod($this->usersFile, 0o644);
        $users = UserRepository::openForAdministration($this->usersFile);
        $users->updateUser('admin', static fn (User $user): User => $user->withRole('viewer'));

        $this->assertSame(0o600, fileperms($this->usersFile) & 0o777, 'The next write repairs the permissions');
    }

    public function testOnlyWritesTakeTheLock(): void
    {
        $users = UserRepository::open($this->usersFile);
        $this->assertFileDoesNotExist($this->usersFile . '.lock');

        $users->updateUser('admin', static fn (User $user): User => $user->withRole('viewer'));
        $this->assertSame(0o600, fileperms($this->usersFile . '.lock') & 0o777);
    }

    public function testItLeavesAnExistingLockFilesPermissions(): void
    {
        touch($this->usersFile . '.lock');
        chmod($this->usersFile . '.lock', 0o660);

        UserRepository::open($this->usersFile)->updateUser('admin', static fn (User $user): User => $user->withRole('viewer'));

        $this->assertSame(0o660, fileperms($this->usersFile . '.lock') & 0o777);
    }

    #[DataProvider('invalidFiles')]
    public function testItRejectsInvalidFiles(string $content, string $message): void
    {
        file_put_contents($this->usersFile, $content);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($message);

        UserRepository::open($this->usersFile);
    }

    /** @return array<string,array{string,string}> */
    public static function invalidFiles(): array
    {
        $valid = UsersFileFactory::record('admin', 'admin123', 'admin');

        return [
            'corrupt JSON' => ['{ invalid json !!!', 'Invalid JSON in users file'],
            'object' => ['{"admin": {}}', 'expected a list of users'],
            'scalar entry' => ['[1]', 'entry 0: expected an object'],
            'missing password hash' => [json_encode([['id' => 'u1', 'username' => 'admin', 'role' => 'admin']]), 'entry 0: "password_hash"'],
            'missing auth revision' => [json_encode([array_diff_key($valid, ['auth_revision' => true])]), 'entry 0: "auth_revision"'],
            'string lockout' => [json_encode([[...$valid, 'locked_until' => '123']]), '"locked_until" must be an integer'],
        ];
    }

    public function testUpdateSeesChangesMadeAfterLoading(): void
    {
        $users = UserRepository::open($this->usersFile);
        UsersFileFactory::write($this->usersFile, [
            UsersFileFactory::record('admin', 'admin123', 'admin'),
            UsersFileFactory::record('added', 'password'),
        ]);

        $users->updateUser('admin', static fn (User $user): User => $user->withRole('viewer'));

        $this->assertSame('viewer', UsersFileFactory::find($this->usersFile, 'admin')['role']);
        $this->assertNotNull(UsersFileFactory::find($this->usersFile, 'added'), 'A write must keep concurrent changes');
        $this->assertNotNull($users->find('added'));
    }

    public function testUpdateSkipsTheWriteWhenTheChangeDeclines(): void
    {
        $users = UserRepository::open($this->usersFile);
        $before = file_get_contents($this->usersFile);

        $this->assertFalse($users->update(static fn (array $all): ?array => null));
        $this->assertFalse($users->updateUser('nobody', static fn (User $user): User => $user));
        $this->assertFalse($users->updateUser('admin', static fn (User $user): ?User => null));

        $this->assertSame($before, file_get_contents($this->usersFile));
    }

    public function testUpdateReportsAnUnwritableDirectory(): void
    {
        $users = UserRepository::openForAdministration(sys_get_temp_dir() . '/missing_users_directory_' . uniqid() . '/users.json');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Users directory is not writable');

        $users->update(static fn (array $all): array => $all);
    }
}
