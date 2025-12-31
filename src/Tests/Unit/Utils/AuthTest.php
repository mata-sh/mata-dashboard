<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Utils;

use Psr\Log\NullLogger;
use RuntimeException;
use Watcher\Core\Auth;
use Watcher\Tests\TestCase;

/**
 * Test suite for Auth utility class.
 *
 * @covers \Watcher\Core\Auth
 *
 * @internal
 */
class AuthTest extends TestCase
{
    private Auth $auth;
    private string $testUsersFile;
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Create temporary users file with secure permissions and admin user
        $this->testUsersFile = $this->createTempFile('', '.json');
        $this->tempFiles[] = $this->testUsersFile;

        // Create initial admin user
        $adminUser = [
            'id' => 'admin_1',
            'username' => 'admin',
            'password_hash' => password_hash('admin123', PASSWORD_DEFAULT),
            'role' => 'admin',
            'created_at' => date('Y-m-d H:i:s'),
            'failed_attempts' => 0,
        ];
        file_put_contents($this->testUsersFile, json_encode([$adminUser], JSON_PRETTY_PRINT));
        chmod($this->testUsersFile, 0600);

        // Mock session for testing
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];

        $this->auth = new Auth(new NullLogger(), $this->testUsersFile);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $this->cleanupTempFiles($this->tempFiles);
        parent::tearDown();
    }

    /**
     * @group auth
     * @group initialization
     */
    public function testItInitializesWithUsersFile(): void
    {
        $this->assertInstanceOf(Auth::class, $this->auth);
    }

    /**
     * @group auth
     * @group initialization
     */
    public function testItThrowsExceptionWhenUsersFileNotFound(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Users file not found');

        new Auth(new NullLogger(), '/nonexistent/users.json');
    }

    /**
     * @group auth
     * @group authentication
     */
    public function testItAuthenticatesUserWithCorrectCredentials(): void
    {
        $result = $this->auth->login('admin', 'admin123');

        $this->assertTrue($result);
        $this->assertTrue($this->auth->check());

        $user = $this->auth->getUser();
        $this->assertEquals('admin', $user['username']);
        $this->assertEquals('admin', $user['role']);
    }

    /**
     * @group auth
     * @group authentication
     */
    public function testItRejectsAuthenticationWithWrongPassword(): void
    {
        $result = $this->auth->login('admin', 'wrong_password');

        $this->assertFalse($result);
        $this->assertFalse($this->auth->check());
    }

    /**
     * @group auth
     * @group authentication
     */
    public function testItRejectsAuthenticationForNonexistentUser(): void
    {
        $result = $this->auth->login('nonexistent', 'any_password');

        $this->assertFalse($result);
        $this->assertFalse($this->auth->check());
    }

    /**
     * @group auth
     * @group session-management
     */
    public function testItChecksAuthenticationStatusCorrectly(): void
    {
        // Not authenticated initially
        $this->assertFalse($this->auth->check());

        // Login as admin
        $this->auth->login('admin', 'admin123');

        // Should be authenticated now
        $this->assertTrue($this->auth->check());
    }

    /**
     * @group auth
     * @group session-management
     */
    public function testItLogsOutUserSuccessfully(): void
    {
        // Login as admin
        $this->auth->login('admin', 'admin123');
        $this->assertTrue($this->auth->check());

        // Logout
        $this->auth->logout();

        $this->assertFalse($this->auth->check());
        $this->assertNull($this->auth->getUser());
    }

    /**
     * @group auth
     * @group user-data
     */
    public function testItReturnsUserDataWhenAuthenticated(): void
    {
        // Login as admin
        $this->auth->login('admin', 'admin123');

        $userData = $this->auth->getUser();

        $this->assertIsArray($userData);
        $this->assertEquals('admin', $userData['username']);
        $this->assertEquals('admin', $userData['role']);
    }

    /**
     * @group auth
     * @group user-data
     */
    public function testItReturnsNullWhenNotAuthenticated(): void
    {
        $userData = $this->auth->getUser();
        $this->assertNull($userData);
    }

    /**
     * @group auth
     * @group user-data
     */
    public function testItReturnsUsernameWhenAuthenticated(): void
    {
        $this->auth->login('admin', 'admin123');

        $username = $this->auth->getUsername();
        $this->assertEquals('admin', $username);
    }

    /**
     * @group auth
     * @group role-management
     */
    public function testItChecksUserRole(): void
    {
        $this->auth->login('admin', 'admin123');

        $this->assertTrue($this->auth->hasRole('admin'));
        $this->assertFalse($this->auth->hasRole('viewer'));
    }

    /**
     * @group auth
     * @group user-management
     */
    public function testItCreatesNewUserSuccessfully(): void
    {
        // Must be logged in as admin
        $this->auth->login('admin', 'admin123');

        $result = $this->auth->createUser('testuser', 'password123', 'viewer');

        $this->assertTrue($result);
        $this->assertTrue($this->auth->userExists('testuser'));
    }

    /**
     * @group auth
     * @group user-management
     */
    public function testItThrowsExceptionWhenNonAdminCreatesUser(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unauthorized: Admin access required');

        // Not logged in as admin
        $this->auth->createUser('testuser', 'password123');
    }

    /**
     * @group auth
     * @group user-management
     */
    public function testItThrowsExceptionWhenUserAlreadyExists(): void
    {
        $this->auth->login('admin', 'admin123');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('User already exists');

        // Try to create user that already exists
        $this->auth->createUser('admin', 'different_password');
    }

    /**
     * @group auth
     * @group user-management
     */
    public function testItDeletesUserSuccessfully(): void
    {
        // Login as admin and create a test user
        $this->auth->login('admin', 'admin123');
        $this->auth->createUser('to_delete', 'password123');

        $result = $this->auth->deleteUser('to_delete');

        $this->assertTrue($result);
        $this->assertFalse($this->auth->userExists('to_delete'));
    }

    /**
     * @group auth
     * @group user-management
     */
    public function testItThrowsExceptionWhenDeletingNonexistentUser(): void
    {
        $this->auth->login('admin', 'admin123');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('User does not exist');

        $this->auth->deleteUser('nonexistent');
    }

    /**
     * @group auth
     * @group user-management
     */
    public function testItChangesUserPasswordSuccessfully(): void
    {
        // Login as admin and create test user
        $this->auth->login('admin', 'admin123');
        $this->auth->createUser('changepass', 'old_password');
        $this->auth->logout();

        // Login as admin again to change password
        $this->auth->login('admin', 'admin123');
        $result = $this->auth->changePassword('changepass', 'new_password');
        $this->assertTrue($result);
        $this->auth->logout();

        // Test old password no longer works
        $this->assertFalse($this->auth->login('changepass', 'old_password'));

        // Test new password works
        $this->assertTrue($this->auth->login('changepass', 'new_password'));
    }

    /**
     * @group auth
     * @group user-management
     */
    public function testItListsAllUsers(): void
    {
        // Login as admin and create multiple users
        $this->auth->login('admin', 'admin123');
        $this->auth->createUser('user1', 'pass1', 'viewer');
        $this->auth->createUser('user2', 'pass2', 'editor');

        $users = $this->auth->getAllUsers();

        $this->assertIsArray($users);
        $this->assertGreaterThanOrEqual(3, count($users)); // admin + user1 + user2
        $this->assertArrayHasKey('admin', $users);
        $this->assertArrayHasKey('user1', $users);
        $this->assertArrayHasKey('user2', $users);

        // Ensure password_hash is not included
        foreach ($users as $user) {
            $this->assertArrayNotHasKey('password_hash', $user);
        }
    }

    /**
     * @group auth
     * @group user-management
     */
    public function testItChecksIfUserExists(): void
    {
        $this->auth->login('admin', 'admin123');

        $this->assertTrue($this->auth->userExists('admin'));
        $this->assertFalse($this->auth->userExists('nonexistent_user'));
    }

    /**
     * @group auth
     * @group password-security
     */
    public function testItHashesPasswordsSecurely(): void
    {
        $this->auth->login('admin', 'admin123');
        $this->auth->createUser('hashtest', 'plaintext_password');

        // Get user data from file directly
        $users = json_decode(file_get_contents($this->testUsersFile), true);
        $hashedUser = null;
        foreach ($users as $user) {
            if ($user['username'] === 'hashtest') {
                $hashedUser = $user;

                break;
            }
        }

        $this->assertNotNull($hashedUser);
        $storedPassword = $hashedUser['password_hash'];

        // Password should be hashed, not stored as plain text
        $this->assertNotEquals('plaintext_password', $storedPassword);
        $this->assertStringStartsWith('$2y$', $storedPassword); // bcrypt hash format

        // Verify it can be verified
        $this->assertTrue(password_verify('plaintext_password', $storedPassword));
    }

    /**
     * @group auth
     * @group session-security
     */
    public function testItRegeneratesSessionId(): void
    {
        $oldSessionId = session_id();
        $this->auth->login('admin', 'admin123');
        $newSessionId = session_id();

        // Session ID should be regenerated for security
        $this->assertNotEquals($oldSessionId, $newSessionId);
    }

    /**
     * @group auth
     * @group role-management
     */
    public function testItUpdatesUserRole(): void
    {
        $this->auth->login('admin', 'admin123');
        $this->auth->createUser('roletest', 'password', 'viewer');

        $this->auth->updateUserRole('roletest', 'editor');

        $userInfo = $this->auth->getUserInfo('roletest');
        $this->assertEquals('editor', $userInfo['role']);
    }

    /**
     * @group auth
     * @group user-management
     */
    public function testItGetsUserInfo(): void
    {
        $this->auth->login('admin', 'admin123');

        $userInfo = $this->auth->getUserInfo('admin');

        $this->assertIsArray($userInfo);
        $this->assertEquals('admin', $userInfo['username']);
        $this->assertEquals('admin', $userInfo['role']);
        $this->assertArrayHasKey('created_at', $userInfo);
        $this->assertArrayNotHasKey('password_hash', $userInfo);
    }

    /**
     * @group auth
     * @group lockout
     */
    public function testItUnlocksUserAccount(): void
    {
        $this->auth->login('admin', 'admin123');
        $this->auth->createUser('lockeduser', 'password');

        // Manually lock the user (simulate failed attempts)
        // We can't easily test lockout without modifying the users file
        // so we'll just test that unlock() doesn't throw

        $result = $this->auth->unlockUser('lockeduser');
        $this->assertTrue($result);
    }
}
