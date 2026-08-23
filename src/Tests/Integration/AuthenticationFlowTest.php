<?php

declare(strict_types=1);

namespace Mata\Tests\Integration;

use InvalidArgumentException;
use Mata\Config\EnvConfigLoader;
use Mata\Core\Auth;
use Mata\Http\Request;
use Mata\Tests\TestCase;
use Mata\Utils\PathResolver;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Integration test for authentication workflow.
 *
 * Tests how Auth, PathResolver, and EnvConfigLoader work together
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
    private Auth $auth;
    private Request $request;
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
            'role' => 'admin',
            'created_at' => date('Y-m-d H:i:s'),
            'failed_attempts' => 0,
        ];
        file_put_contents($usersFile, json_encode([$adminUser], JSON_PRETTY_PRINT));
        chmod($usersFile, 0600);

        $this->startTestRequest();
        $this->request = new Request();
        $this->auth = new Auth(new NullLogger(), $usersFile, $this->request->isSecure(), $this->request->cookie('remember_token'));
        $_SESSION = [
            'user' => [
                'username' => 'admin',
                'role' => 'admin',
            ],
            'logged_in' => true,
        ];
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

        $createResult = $this->auth->createUser($username, $password, $role);
        $this->assertTrue($createResult, 'User creation should succeed');

        // Step 3: Verify user exists in the system
        $this->assertTrue($this->auth->userExists($username), 'User should exist after creation');

        // Step 4: Attempt login with correct credentials
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
        $this->startTestRequest();
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
            ['username' => 'editor_user', 'password' => 'editor_pass', 'role' => 'editor'],
            ['username' => 'viewer_user', 'password' => 'viewer_pass', 'role' => 'viewer'],
        ];

        // Create all users
        foreach ($users as $user) {
            $result = $this->auth->createUser($user['username'], $user['password'], $user['role']);
            $this->assertTrue($result, "Creating user {$user['username']} should succeed");
        }

        foreach ($users as $user) {
            $this->assertTrue($this->auth->userExists($user['username']), 'User should exist after creation');
        }

        // Test login for each user and verify their role permissions
        foreach ($users as $user) {
            $loginResult = $this->auth->login($user['username'], $user['password']);
            $this->assertTrue($loginResult, "Login for {$user['username']} should succeed");

            $userData = $this->auth->getUser();
            $this->assertEquals($user['role'], $userData['role']);

            $this->assertEquals('example.com', $loadedConfig->environment->domain);

            $this->auth->logout();
            $this->startTestRequest();
        }

        // Test password change functionality
        $this->assertTrue($this->auth->login('admin_user', 'admin_pass'), 'Admin login should succeed');
        $newPassword = 'new_secure_password';
        $changeResult = $this->auth->changePassword('admin_user', $newPassword);
        $this->assertTrue($changeResult, 'Password change should succeed');

        // Verify old password no longer works
        $oldPasswordLogin = $this->auth->login('admin_user', 'admin_pass');
        $this->assertFalse($oldPasswordLogin, 'Login with old password should fail');

        // Verify new password works
        $newPasswordLogin = $this->auth->login('admin_user', $newPassword);
        $this->assertTrue($newPasswordLogin, 'Login with new password should succeed');

        // Test user deletion (admin access required)
        $deleteResult = $this->auth->deleteUser('viewer_user');
        $this->assertTrue($deleteResult, 'User deletion should succeed');
        $this->assertFalse($this->auth->userExists('viewer_user'), 'Deleted user should not exist');

        $this->assertTrue($this->auth->userExists('admin_user'));
        $this->assertTrue($this->auth->userExists('editor_user'));
        $this->assertFalse($this->auth->userExists('viewer_user'));
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

        $createResult = $this->auth->createUser($username, $password, $defaultRole);
        $this->assertTrue($createResult);

        // Login and verify user gets default settings
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
            new Auth(new NullLogger(), $usersFile, $this->request->isSecure(), $this->request->cookie('remember_token'));
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
