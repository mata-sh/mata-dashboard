<?php

declare(strict_types=1);

namespace Mata\Tests\Integration;

use InvalidArgumentException;
use Mata\Config\EnvConfigLoader;
use Mata\Core\Session;
use Mata\Models\Auth\UserRepository;
use Mata\Services\Auth\UserAdministrator;
use Mata\Services\Auth\UserAuthenticator;
use Mata\Tests\TestCase;
use Mata\Utils\PathResolver;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Integration test for authentication workflow.
 *
 * Tests how UserAuthenticator, UserAdministrator, PathResolver, and EnvConfigLoader work together
 * in a realistic authentication scenario.
 *
 * @internal
 *
 * @coversNothing
 */
class AuthenticationFlowTest extends TestCase
{
    private PathResolver $pathResolver;
    private EnvConfigLoader $configLoader;
    private UserAuthenticator $auth;
    private string $tempAppPath;
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Create temporary application structure
        $this->tempAppPath = $this->createTempDirectory();
        $this->tempFiles[] = $this->tempAppPath;

        // Create necessary directories
        mkdir($this->tempAppPath . '/config', 0777, true);
        mkdir($this->tempAppPath . '/temp', 0777, true);
        mkdir($this->tempAppPath . '/logs', 0777, true);
        mkdir($this->tempAppPath . '/public', 0777, true);

        // Initialize components with a temp entry point file
        $entryPoint = $this->tempAppPath . '/public/index.php';
        file_put_contents($entryPoint, '<?php');
        $this->pathResolver = PathResolver::fromEntryPoint($entryPoint);
        $this->configLoader = new EnvConfigLoader();

        // Create users file
        $usersFile = $this->pathResolver->getConfigPath('users.json');
        $adminUser = [
            'id' => 'admin_1',
            'username' => 'admin',
            'password_hash' => password_hash('admin123', PASSWORD_DEFAULT),
            'auth_revision' => bin2hex(random_bytes(32)),
            'role' => 'admin',
            'created_at' => date('Y-m-d H:i:s'),
            'failed_attempts' => 0,
        ];
        file_put_contents($usersFile, json_encode([$adminUser], JSON_PRETTY_PRINT));
        chmod($usersFile, 0600);

        $this->startTestRequest();
        $this->auth = $this->requestAuth();
        $this->auth->login('admin', 'admin123');
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $this->cleanupTempFiles($this->tempFiles);
        parent::tearDown();
    }

    /**
     * @test
     *
     * @group integration
     * @group authentication-flow
     */
    public function testItHandlesCompleteUserRegistrationAndLoginFlow(): void
    {
        $this->writeEnvFile([
            'APP_ENV=testing',
            'DOMAIN=example.com',
            'DB_HOST=localhost',
            'DB_PORT=3007',
            'DB_USERNAME=test_user',
            'DB_PASSWORD=test_pass',
            'DB_DATABASE=test_db',
            'ORGANIZATION_TITLE="MATA Dashboard"',
        ]);

        $loadedConfig = $this->configLoader->load(['.env'], $this->pathResolver->getConfigPath());

        // Step 2: Create new user account
        $username = 'integration_user';
        $password = 'secure_password_123';
        $role = 'viewer';

        $this->administrator()->createUser($username, $password, $role);

        // Step 3: Verify user exists in the system
        $this->assertArrayHasKey($username, $this->administrator()->listUsers(), 'User should exist after creation');

        // Step 4: Attempt login with correct credentials
        $this->auth = $this->requestAuth();
        $loginResult = $this->auth->login($username, $password);
        $this->assertTrue($loginResult, 'Login with correct credentials should succeed');

        // Step 5: Verify authentication state
        $this->assertTrue($this->auth->check(), 'User should be authenticated after login');

        // Step 6: Get user data and verify it's correct
        $userData = $this->auth->getUser();
        $this->assertIsArray($userData);
        $this->assertEquals($username, $userData['username']);
        $this->assertEquals($role, $userData['role']);
        $this->assertArrayHasKey('id', $userData);

        // Step 7: Test logout process
        $this->auth->logout();
        $this->assertFalse($this->auth->check(), 'User should not be authenticated after logout');
        $this->assertNull($this->auth->getUser(), 'User data should be null after logout');

        // Step 8: Verify failed login with wrong password
        $this->auth = $this->requestAuth();
        $wrongPasswordResult = $this->auth->login($username, 'wrong_password');
        $this->assertFalse($wrongPasswordResult, 'Login with wrong password should fail');
        $this->assertFalse($this->auth->check(), 'User should not be authenticated after failed login');
    }

    /**
     * @test
     *
     * @group integration
     * @group multi-user-management
     */
    public function testItHandlesMultipleUserManagementScenarios(): void
    {
        $this->writeEnvFile([
            'APP_ENV=testing',
            'DOMAIN=example.com',
            'DB_HOST=localhost',
            'DB_PORT=3007',
            'DB_USERNAME=test_user',
            'DB_PASSWORD=test_pass',
            'DB_DATABASE=test_db',
            'ORGANIZATION_TITLE="MATA Dashboard"',
        ]);

        $loadedConfig = $this->configLoader->load(['.env'], $this->pathResolver->getConfigPath());

        // Create users with different roles
        $users = [
            ['username' => 'admin_user', 'password' => 'admin_pass', 'role' => 'admin'],
            ['username' => 'viewer_user', 'password' => 'viewer_pass', 'role' => 'viewer'],
            ['username' => 'other_viewer', 'password' => 'other_pass', 'role' => 'viewer'],
        ];

        // Create all users
        foreach ($users as $user) {
            $this->administrator()->createUser($user['username'], $user['password'], $user['role']);
        }

        foreach ($users as $user) {
            $this->assertArrayHasKey($user['username'], $this->administrator()->listUsers(), 'User should exist after creation');
        }

        // Test login for each user and verify their role permissions
        foreach ($users as $user) {
            $this->auth = $this->requestAuth();
            $loginResult = $this->auth->login($user['username'], $user['password']);
            $this->assertTrue($loginResult, "Login for {$user['username']} should succeed");

            $userData = $this->auth->getUser();
            $this->assertEquals($user['role'], $userData['role']);

            $this->assertEquals('example.com', $loadedConfig->environment->domain);

            $this->auth->logout();
        }

        // Test password change functionality
        $this->auth = $this->requestAuth();
        $this->assertTrue($this->auth->login('admin_user', 'admin_pass'), 'Admin login should succeed');
        $newPassword = 'new_secure_password';
        $this->administrator()->changePassword('admin_user', $newPassword);
        $this->assertFalse($this->requestAuth($_SESSION)->check(), 'A password change should sign the account out');

        // Verify old password no longer works
        $this->auth = $this->requestAuth();
        $oldPasswordLogin = $this->auth->login('admin_user', 'admin_pass');
        $this->assertFalse($oldPasswordLogin, 'Login with old password should fail');

        // Verify new password works
        $newPasswordLogin = $this->auth->login('admin_user', $newPassword);
        $this->assertTrue($newPasswordLogin, 'Login with new password should succeed');

        // Test user deletion
        $this->administrator()->deleteUser('viewer_user');

        $remaining = array_keys($this->administrator()->listUsers());
        $this->assertSame(['admin', 'admin_user', 'other_viewer'], $remaining, 'Deleted user should not exist');
    }

    /**
     * @test
     *
     * @group integration
     * @group configuration-driven-auth
     */
    public function testItUsesConfigurationToDriveAuthenticationBehavior(): void
    {
        $this->writeEnvFile([
            'APP_ENV=testing',
            'DOMAIN=example.com',
            'DB_HOST=localhost',
            'DB_PORT=3007',
            'DB_USERNAME=test_user',
            'DB_PASSWORD=test_pass',
            'DB_DATABASE=test_db',
            'ORGANIZATION_TITLE="MATA Dashboard"',
            'DASHBOARD_SERVER_COLUMNS=3',
            'DASHBOARD_CONTAINER_WIDTH=1200',
            'SYM_MAILER_DSN=smtp://localhost',
        ]);

        $config = $this->configLoader->load(['.env'], $this->pathResolver->getConfigPath());

        // Test configuration-driven user creation
        $username = 'config_driven_user';
        $password = 'ConfigPass123!'; // Meets requirements
        $defaultRole = 'viewer';

        $this->administrator()->createUser($username, $password, $defaultRole);

        // Login and verify user gets default settings
        $this->auth = $this->requestAuth();
        $loginResult = $this->auth->login($username, $password);
        $this->assertTrue($loginResult);

        $userData = $this->auth->getUser();
        $this->assertEquals($defaultRole, $userData['role']);

        $this->assertEquals(3, $config->dashboard->serverColumns);
        $this->assertEquals(1200, $config->dashboard->containerWidth);

        // Verify path resolution works with config
        $logFile = $this->pathResolver->logsPath . '/auth.log';
        $this->assertStringContainsString('/logs/auth.log', $logFile);

        $validation = $this->configLoader->validateConfig($config);
        $this->assertTrue($validation['is_valid']);
    }

    /**
     * @test
     *
     * @group integration
     * @group error-handling
     */
    public function testItHandlesIntegrationErrorScenariosGracefully(): void
    {
        // Test scenario where configuration file is missing
        $this->writeEnvFile([
            'APP_ENV=testing',
            'DOMAIN=',
        ]);

        foreach (['DB_HOST', 'DB_USERNAME', 'DB_PASSWORD', 'DB_DATABASE'] as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        }

        try {
            $this->configLoader->load(['.env'], $this->pathResolver->getConfigPath());
            $this->fail('Should throw exception for missing required env vars');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Required environment variable', $e->getMessage());
        }

        // Test scenario where users file becomes corrupted
        $usersFile = $this->pathResolver->getConfigPath('users.json');
        file_put_contents($usersFile, 'invalid json content');
        chmod($usersFile, 0600);

        try {
            UserRepository::open($usersFile);
            $this->fail('Should throw exception for corrupted users file');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Invalid JSON in users file', $e->getMessage());
        }

        // Test path resolution with non-existent directories
        $nonExistentPath = $this->pathResolver->tempPath . '/deep/nested/file.json';
        $this->assertStringContainsString('deep/nested/file.json', $nonExistentPath);

        // Test configuration validation with missing required keys
        $this->writeEnvFile([
            'APP_ENV=testing',
            'DOMAIN=',
            'DB_HOST=',
            'DB_USERNAME=',
            'DB_PASSWORD=',
            'DB_DATABASE=',
        ], 'incomplete.env');

        try {
            $config = $this->configLoader->load(['incomplete.env'], $this->pathResolver->getConfigPath());
            $validation = $this->configLoader->validateConfig($config);
            $this->assertFalse($validation['is_valid']);
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Required environment variable', $e->getMessage());
        }
    }

    public function testPasswordResetRevokesOldAccessWithoutDamagingFreshCredentials(): void
    {
        $adminSession = $_SESSION;
        $this->administrator()->createUser('operator', 'old-password', 'viewer');
        $this->requestAuth()->login('operator', 'old-password', true);
        $oldSession = $_SESSION;
        $oldCookie = $this->rememberCookieFor('operator');

        $this->administrator()->changePassword('operator', 'new-password');
        $this->assertTrue($this->requestAuth($adminSession)->check(), 'An unrelated account stays authenticated');
        $resetUser = $this->storedUser('operator');
        $this->assertNotSame($oldSession['user']['auth_revision'], $resetUser['auth_revision']);
        $this->assertArrayNotHasKey('remember_token_hash', $resetUser);
        $this->assertArrayNotHasKey('remember_expires', $resetUser);

        $this->assertUnauthenticated($this->requestAuth($oldSession, $oldCookie));
        $this->assertUnauthenticated($this->requestAuth([], $oldCookie));
        $fresh = $this->requestAuth();
        $this->assertFalse($fresh->login('operator', 'old-password'));
        $this->assertTrue($fresh->login('operator', 'new-password', true));
        $freshSession = $_SESSION;
        $freshCookie = $this->rememberCookieFor('operator');
        $freshUser = $this->storedUser('operator');

        $this->assertUnauthenticated($this->requestAuth($oldSession, $oldCookie));
        $this->assertSame($freshUser, $this->storedUser('operator'), 'Stale replay must not revoke a fresh token');
        $this->assertTrue($this->requestAuth($freshSession)->check());
        $remembered = $this->requestAuth([], $freshCookie);
        $this->assertTrue($remembered->check());
        $this->assertSame($freshUser['auth_revision'], Session::get('user')['auth_revision']);
        $this->assertNotSame($freshUser['remember_token_hash'], $this->storedUser('operator')['remember_token_hash']);
        $this->assertTrue($this->requestAuth($adminSession)->hasRole('admin'));
    }

    public function testDemotionAndUsernameReuseCannotRestoreOldAccess(): void
    {
        $this->administrator()->createUser('operator', 'password', 'admin');
        $this->requestAuth()->login('operator', 'password', true);
        $adminOperatorSession = $_SESSION;
        $adminOperatorCookie = $this->rememberCookieFor('operator');

        $this->administrator()->changeRole('operator', 'viewer');
        $demoted = $this->storedUser('operator');
        $this->assertNotSame($adminOperatorSession['user']['auth_revision'], $demoted['auth_revision']);
        $this->assertArrayNotHasKey('remember_token_hash', $demoted);
        $this->assertArrayNotHasKey('remember_expires', $demoted);
        $this->assertUnauthenticated($this->requestAuth($adminOperatorSession));
        $this->assertUnauthenticated($this->requestAuth([], $adminOperatorCookie));

        $viewer = $this->requestAuth();
        $this->assertTrue($viewer->login('operator', 'password', true));
        $this->assertTrue($viewer->hasRole('viewer'));
        $viewerSession = $_SESSION;
        $viewerCookie = $this->rememberCookieFor('operator');
        $this->administrator()->deleteUser('operator');
        $this->assertUnauthenticated($this->requestAuth($viewerSession, $viewerCookie));

        $this->administrator()->createUser('operator', 'password', 'admin');
        $this->assertNotSame($viewerSession['user']['auth_revision'], $this->storedUser('operator')['auth_revision']);
        $this->assertUnauthenticated($this->requestAuth($viewerSession, $viewerCookie));
        $this->assertUnauthenticated($this->requestAuth($adminOperatorSession, $adminOperatorCookie));
        $replacement = $this->requestAuth();
        $this->assertTrue($replacement->login('operator', 'password'));
        $this->assertTrue($replacement->hasRole('admin'));
    }

    private function requestAuth(array $session = [], ?string $cookie = null): UserAuthenticator
    {
        Session::close();
        Session::start();
        $_SESSION = $session;

        $auth = new UserAuthenticator(UserRepository::open($this->pathResolver->getConfigPath('users.json')), new NullLogger(), false);
        $auth->resumeFromRememberCookie($cookie);

        return $auth;
    }

    private function administrator(): UserAdministrator
    {
        return new UserAdministrator(UserRepository::openForAdministration($this->pathResolver->getConfigPath('users.json')), new NullLogger());
    }

    private function assertUnauthenticated(UserAuthenticator $auth): void
    {
        $this->assertNull($auth->getUser());
        $this->assertNull($auth->getUsername());
        $this->assertFalse($auth->hasRole('admin'));
        $this->assertFalse($auth->check());
        $this->assertSame(PHP_SESSION_ACTIVE, session_status());
    }

    private function storedUser(string $username): array
    {
        $users = json_decode(file_get_contents($this->pathResolver->getConfigPath('users.json')), true);

        return array_column($users, null, 'username')[$username];
    }

    private function rememberCookieFor(string $username): string
    {
        // CLI cannot inspect Set-Cookie headers; seed a known browser credential.
        $token = bin2hex(random_bytes(32));
        $usersFile = $this->pathResolver->getConfigPath('users.json');
        $users = json_decode(file_get_contents($usersFile), true);
        foreach ($users as &$user) {
            if ($user['username'] === $username) {
                $user['remember_token_hash'] = hash('sha256', $token);
                $user['remember_expires'] = time() + 3600;
            }
        }
        unset($user);
        file_put_contents($usersFile, json_encode($users));

        return $username . '|' . $token;
    }

    private function writeEnvFile(array $lines, string $filename = '.env'): void
    {
        $envFile = $this->pathResolver->getConfigPath($filename);
        foreach ($lines as $line) {
            $parts = explode('=', $line, 2);
            if ($parts[0] !== '') {
                unset($_ENV[$parts[0]], $_SERVER[$parts[0]]);

                putenv($parts[0]);
            }
        }
        file_put_contents($envFile, implode("\n", $lines));
    }
}
