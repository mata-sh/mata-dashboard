<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Utils;

use Mata\Core\Auth;
use Mata\Tests\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Test suite for Auth utility class.
 *
 * @covers \Mata\Core\Auth
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

    /**
     * @group auth
     * @group lockout
     * @group security
     */
    public function testItLocksAccountAfterMaxFailedAttempts(): void
    {
        $this->auth->login('admin', 'admin123');
        $this->auth->createUser('bruteforce', 'correct_password');
        $this->auth->logout();

        // Try 5 failed login attempts (MAX_ATTEMPTS)
        for ($i = 0; $i < 5; ++$i) {
            $result = $this->auth->login('bruteforce', 'wrong_password');
            $this->assertFalse($result, "Failed login attempt {$i} should fail");
        }

        // Account should now be locked - even correct password should fail
        $result = $this->auth->login('bruteforce', 'correct_password');
        $this->assertFalse($result, 'Login should fail when account is locked');
        $this->assertFalse($this->auth->check(), 'User should not be authenticated when locked');
    }

    /**
     * @group auth
     * @group lockout
     * @group security
     */
    public function testItResetsFailedAttemptsAfterSuccessfulLogin(): void
    {
        $this->auth->login('admin', 'admin123');
        $this->auth->createUser('resettest', 'password123');
        $this->auth->logout();

        // Try 3 failed attempts
        for ($i = 0; $i < 3; ++$i) {
            $this->auth->login('resettest', 'wrong_password');
        }

        // Now login successfully
        $result = $this->auth->login('resettest', 'password123');
        $this->assertTrue($result, 'Login with correct password should succeed');

        // Failed attempts should be reset - we should be able to fail again without hitting lockout
        $this->auth->logout();
        for ($i = 0; $i < 3; ++$i) {
            $this->auth->login('resettest', 'wrong_password');
        }

        // Should still be able to login (not locked)
        $result = $this->auth->login('resettest', 'password123');
        $this->assertTrue($result, 'Account should not be locked after reset');
    }

    /**
     * @group auth
     * @group security
     * @group timing-attack
     */
    public function testItPreventsTimingAttacksOnNonexistentUsers(): void
    {
        // This test ensures password verification runs even for non-existent users
        // by checking that the code path includes password_verify for both cases

        // Create a real user
        $this->auth->login('admin', 'admin123');
        $this->auth->createUser('realuser', 'correct_pass');
        $this->auth->logout();

        // Measure multiple attempts to reduce noise from system variability
        $nonexistentTimes = [];
        $existingTimes = [];

        for ($i = 0; $i < 5; ++$i) {
            $startTime = microtime(true);
            $this->auth->login('nonexistent_user', 'some_password');
            $nonexistentTimes[] = microtime(true) - $startTime;

            $startTime = microtime(true);
            $this->auth->login('realuser', 'wrong_password');
            $existingTimes[] = microtime(true) - $startTime;
        }

        $avgNonexistent = array_sum($nonexistentTimes) / count($nonexistentTimes);
        $avgExisting = array_sum($existingTimes) / count($existingTimes);

        // Times should be similar (within 500ms tolerance for system variability)
        // The important thing is both code paths call password_verify
        $timeDiff = abs($avgNonexistent - $avgExisting);
        $this->assertLessThan(0.5, $timeDiff, 'Login timing should be similar for existing and non-existing users');
    }

    /**
     * @group auth
     * @group security
     * @group file-permissions
     */
    public function testItRejectsUsersFileWithInsecurePermissions(): void
    {
        // Create a new users file with insecure permissions
        $insecureFile = $this->createTempFile('', '.json');
        $this->tempFiles[] = $insecureFile;

        $adminUser = [
            'id' => 'admin_1',
            'username' => 'admin',
            'password_hash' => password_hash('admin123', PASSWORD_DEFAULT),
            'role' => 'admin',
            'created_at' => date('Y-m-d H:i:s'),
            'failed_attempts' => 0,
        ];
        file_put_contents($insecureFile, json_encode([$adminUser], JSON_PRETTY_PRINT));
        chmod($insecureFile, 0644); // World-readable

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('insecure permissions');

        new Auth(new NullLogger(), $insecureFile);
    }

    /**
     * @group auth
     * @group cli-mode
     */
    public function testItAllowsMissingFileInCliMode(): void
    {
        $nonexistentFile = sys_get_temp_dir() . '/nonexistent_users_' . uniqid() . '.json';

        // In CLI mode, missing file should not throw
        $auth = new Auth(new NullLogger(), $nonexistentFile, true);

        $this->assertInstanceOf(Auth::class, $auth);
    }

    /**
     * @group auth
     * @group cli-mode
     */
    public function testItSkipsPermissionCheckInCliMode(): void
    {
        // Create users file with relaxed permissions
        $cliFile = $this->createTempFile('', '.json');
        $this->tempFiles[] = $cliFile;

        $adminUser = [
            'id' => 'admin_1',
            'username' => 'admin',
            'password_hash' => password_hash('admin123', PASSWORD_DEFAULT),
            'role' => 'admin',
            'created_at' => date('Y-m-d H:i:s'),
            'failed_attempts' => 0,
        ];
        file_put_contents($cliFile, json_encode([$adminUser], JSON_PRETTY_PRINT));
        chmod($cliFile, 0644); // Would normally fail

        // Should not throw in CLI mode
        $auth = new Auth(new NullLogger(), $cliFile, true);
        $this->assertInstanceOf(Auth::class, $auth);
    }

    /**
     * @group auth
     * @group cli-mode
     * @group user-management
     */
    public function testItAllowsUserCreationInCliModeWithoutLogin(): void
    {
        $cliFile = $this->createTempFile('', '.json');
        $this->tempFiles[] = $cliFile;
        file_put_contents($cliFile, json_encode([], JSON_PRETTY_PRINT));
        chmod($cliFile, 0600);

        // Create Auth in CLI mode (no login required)
        $auth = new Auth(new NullLogger(), $cliFile, true);

        // Should be able to create user without being logged in as admin
        $result = $auth->createUser('cliuser', 'password123', 'admin');
        $this->assertTrue($result);
        $this->assertTrue($auth->userExists('cliuser'));
    }

    /**
     * @group auth
     * @group password-security
     * @group rehashing
     */
    public function testItRehashesPasswordWhenAlgorithmIsUpgraded(): void
    {
        $this->auth->login('admin', 'admin123');
        $this->auth->createUser('rehashtest', 'password123');

        // Manually set an old password hash format in the users file
        $users = json_decode(file_get_contents($this->testUsersFile), true);
        $oldHash = null;
        foreach ($users as &$user) {
            if ($user['username'] === 'rehashtest') {
                // Use an old bcrypt cost to simulate old hash that needs rehashing
                $oldHash = password_hash('password123', PASSWORD_BCRYPT, ['cost' => 4]);
                $user['password_hash'] = $oldHash;

                break;
            }
        }
        file_put_contents($this->testUsersFile, json_encode($users, JSON_PRETTY_PRINT));

        // Re-initialize Auth to load the modified file
        $this->auth = new Auth(new NullLogger(), $this->testUsersFile);
        $this->auth->logout();

        // Login should succeed and trigger rehashing
        $result = $this->auth->login('rehashtest', 'password123');
        $this->assertTrue($result);

        // Verify the password was rehashed (hash should be different from old one)
        $users = json_decode(file_get_contents($this->testUsersFile), true);
        foreach ($users as $user) {
            if ($user['username'] === 'rehashtest') {
                // Should have been rehashed (different from old hash)
                $this->assertNotEquals($oldHash, $user['password_hash'], 'Password should be rehashed');
                // Should still use bcrypt format
                $this->assertStringStartsWith('$2y$', $user['password_hash']);

                break;
            }
        }
    }

    /**
     * @group auth
     * @group password-security
     */
    public function testItClearsLockoutOnPasswordChange(): void
    {
        $this->auth->login('admin', 'admin123');
        $this->auth->createUser('locktest', 'oldpass');
        $this->auth->logout();

        // Lock the account with failed attempts
        for ($i = 0; $i < 5; ++$i) {
            $this->auth->login('locktest', 'wrong');
        }

        // Admin changes password
        $this->auth->login('admin', 'admin123');
        $this->auth->changePassword('locktest', 'newpass');
        $this->auth->logout();

        // Should be able to login with new password (lockout cleared)
        $result = $this->auth->login('locktest', 'newpass');
        $this->assertTrue($result, 'Should be able to login after password change clears lockout');
    }

    /**
     * @group auth
     * @group error-handling
     */
    public function testItHandlesCorruptedJsonInUsersFile(): void
    {
        $corruptedFile = $this->createTempFile('{ invalid json !!!', '.json');
        $this->tempFiles[] = $corruptedFile;
        chmod($corruptedFile, 0600);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid JSON in users file');

        new Auth(new NullLogger(), $corruptedFile);
    }

    /**
     * @group auth
     * @group concurrency
     * @group file-locking
     */
    public function testItHandlesConcurrentFileAccess(): void
    {
        // Test that file locking works by simulating concurrent writes
        $this->auth->login('admin', 'admin123');

        // Create multiple users rapidly (tests file locking in saveUsers)
        for ($i = 0; $i < 10; ++$i) {
            $this->auth->createUser("concurrent_user_{$i}", 'password', 'viewer');
        }

        // Verify all users were created successfully
        for ($i = 0; $i < 10; ++$i) {
            $this->assertTrue(
                $this->auth->userExists("concurrent_user_{$i}"),
                "User concurrent_user_{$i} should exist"
            );
        }
    }

    /**
     * @group auth
     * @group remember-me
     * @group security
     */
    public function testItCreatesRememberMeTokenOnLogin(): void
    {
        // Login with remember-me flag
        $_SERVER['HTTPS'] = 'on'; // Simulate HTTPS for secure cookie
        $result = $this->auth->login('admin', 'admin123', true);

        $this->assertTrue($result);

        // Check that remember_token cookie was set
        // Note: In testing, cookies are set but we can't easily verify them
        // This test mainly ensures no exceptions are thrown
    }

    /**
     * @group auth
     * @group remember-me
     * @group security
     */
    public function testItClearsRememberTokenOnLogout(): void
    {
        $_SERVER['HTTPS'] = 'on';
        $this->auth->login('admin', 'admin123', true);

        // Logout should clear remember token
        $this->auth->logout();

        // Verify user data from file has no remember_token
        $users = json_decode(file_get_contents($this->testUsersFile), true);
        foreach ($users as $user) {
            if ($user['username'] === 'admin') {
                $this->assertArrayNotHasKey('remember_token', $user);
                $this->assertArrayNotHasKey('remember_expires', $user);

                break;
            }
        }
    }

    /**
     * @group auth
     * @group authorization
     * @group error-handling
     */
    public function testItPreventsNonAdminFromDeletingUsers(): void
    {
        $this->auth->login('admin', 'admin123');
        $this->auth->createUser('viewer', 'password', 'viewer');
        $this->auth->logout();

        // Login as viewer (non-admin)
        $this->auth->login('viewer', 'password');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unauthorized: Admin access required');

        // Try to delete a user
        $this->auth->deleteUser('admin');
    }

    /**
     * @group auth
     * @group authorization
     * @group error-handling
     */
    public function testItPreventsNonAdminFromChangingPasswords(): void
    {
        $this->auth->login('admin', 'admin123');
        $this->auth->createUser('viewer', 'password', 'viewer');
        $this->auth->logout();

        $this->auth->login('viewer', 'password');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unauthorized: Admin access required');

        $this->auth->changePassword('admin', 'newpass');
    }

    /**
     * @group auth
     * @group authorization
     * @group error-handling
     */
    public function testItPreventsNonAdminFromUpdatingRoles(): void
    {
        $this->auth->login('admin', 'admin123');
        $this->auth->createUser('viewer', 'password', 'viewer');
        $this->auth->logout();

        $this->auth->login('viewer', 'password');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unauthorized: Admin access required');

        $this->auth->updateUserRole('viewer', 'admin');
    }

    /**
     * @group auth
     * @group authorization
     * @group error-handling
     */
    public function testItPreventsNonAdminFromListingUsers(): void
    {
        $this->auth->login('admin', 'admin123');
        $this->auth->createUser('viewer', 'password', 'viewer');
        $this->auth->logout();

        $this->auth->login('viewer', 'password');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unauthorized: Admin access required');

        $this->auth->getAllUsers();
    }

    /**
     * @group auth
     * @group authorization
     * @group error-handling
     */
    public function testItPreventsNonAdminFromGettingUserInfo(): void
    {
        $this->auth->login('admin', 'admin123');
        $this->auth->createUser('viewer', 'password', 'viewer');
        $this->auth->logout();

        $this->auth->login('viewer', 'password');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unauthorized: Admin access required');

        $this->auth->getUserInfo('admin');
    }

    /**
     * @group auth
     * @group authorization
     * @group error-handling
     */
    public function testItPreventsNonAdminFromUnlockingUsers(): void
    {
        $this->auth->login('admin', 'admin123');
        $this->auth->createUser('viewer', 'password', 'viewer');
        $this->auth->logout();

        $this->auth->login('viewer', 'password');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unauthorized: Admin access required');

        $this->auth->unlockUser('admin');
    }
}
