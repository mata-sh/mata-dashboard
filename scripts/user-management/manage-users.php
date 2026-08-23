#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Mata\Bootstrap\ScriptRuntime;
use Mata\Core\Auth;
use Mata\Utils\UsersFilePathResolver;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

if (php_sapi_name() !== 'cli') {
    exit("ERROR: This script can only be executed from the command line.\n");
}

$runtime = ScriptRuntime::boot($_SERVER['argv'] ?? []);
$paths = $runtime->paths;
$appConfig = $runtime->appConfig;
$arguments = $runtime->arguments;

$currentUser = posix_getpwuid(posix_geteuid());
if ($currentUser === false) {
    exit("ERROR: Cannot determine the current operating-system user.\n");
}

$env = $appConfig->environment->env;
$strictUserCheck = $appConfig->cliSecurity->strictUserCheck;
$allowedUsers = $appConfig->cliSecurity->allowedUsers;

foreach ($arguments as $argument) {
    if (str_starts_with($argument, '--env=')) {
        $env = substr($argument, 6);
    }
}

$arguments = array_values(array_filter(
    $arguments,
    static fn (string $argument): bool => !str_starts_with($argument, '--env=')
));
$action = $arguments[1] ?? 'help';
$scriptPath = $arguments[0] ?? __FILE__;

if ($strictUserCheck && !in_array($currentUser['name'], $allowedUsers, true)) {
    echo "❌ SECURITY ERROR: Unauthorized user\n\n";
    echo 'This script can only be executed by: ' . implode(', ', $allowedUsers) . "\n";
    echo "Current user: {$currentUser['name']}\n\n";
    echo "To allow this user, add to config.env:\n";
    echo '  CLI_ALLOWED_USERS=' . implode(',', array_merge($allowedUsers, [$currentUser['name']])) . "\n\n";
    echo "Or disable strict checking (not recommended for production):\n";
    echo "  CLI_STRICT_USER_CHECK=OFF\n\n";
    echo "Alternatively, run with: sudo php {$scriptPath}\n";

    exit(1);
}

$validEnvironments = ['dev', 'demo', 'prod'];
if (!in_array($env, $validEnvironments, true)) {
    echo "❌ SECURITY ERROR: Invalid environment\n\n";
    echo 'Valid environments: ' . implode(', ', $validEnvironments) . "\n";
    echo "Provided: {$env}\n";

    exit(1);
}

if ($env === 'prod' && $action !== 'list') {
    echo "⚠️  WARNING: You are about to modify PRODUCTION users!\n";
    echo "Environment: {$env}\n";
    echo "Action: {$action}\n";
    echo "Operator: {$currentUser['name']}\n\n";
    echo "Type 'CONFIRM PRODUCTION' to continue: ";
    $confirmation = fgets(STDIN);
    if ($confirmation === false || trim($confirmation) !== 'CONFIRM PRODUCTION') {
        echo "❌ Cancelled\n";

        exit(1);
    }
    echo "\n";
}

try {
    $usersFile = UsersFilePathResolver::resolve($paths->appPath);
} catch (InvalidArgumentException $e) {
    exit("❌ Invalid MATA_USERS_FILE: {$e->getMessage()}\n");
}

// Setup audit logger
$logDir = __DIR__ . '/../../logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}
$auditLogFile = $logDir . '/user-management-audit.log';

// Show help without requiring file access
if ($action === 'help' || $action === '--help' || $action === '-h') {
    echo "Usage: php manage-users.php [action] [options] [--env=ENV]\n\n";
    echo "SECURITY:\n";
    if ($strictUserCheck) {
        echo "  ⚠️  Strict user checking: ENABLED\n";
        echo '  - Authorized users: ' . implode(', ', $allowedUsers) . "\n";
        echo "  - Current user: {$currentUser['name']}\n";
    } else {
        echo "  ℹ️  Strict user checking: DISABLED (shared hosting mode)\n";
        echo "  - To enable, add to config.env: CLI_STRICT_USER_CHECK=ON\n";
    }
    echo "  - All operations are logged to: logs/user-management-audit.log\n";
    echo "  - Production operations require explicit confirmation\n\n";
    echo "Actions:\n";
    echo "  create [username] [role]  - Create new user\n";
    echo "  list                       - List all users\n";
    echo "  unlock [username]          - Unlock user account\n";
    echo "  delete [username]          - Delete user\n";
    echo "  passwd [username]          - Change password\n\n";
    echo "Options:\n";
    echo "  --env=ENV                  - Target environment (dev/demo/prod) [default: \$APP_ENV or 'dev']\n\n";
    echo "Examples:\n";
    echo "  php manage-users.php create admin admin\n";
    echo "  php manage-users.php list --env=prod\n";
    echo "  php manage-users.php passwd admin --env=prod\n\n";
    echo "Configuration (add to config.env):\n";
    echo "  CLI_STRICT_USER_CHECK=ON|OFF       - Enable/disable user restrictions\n";
    echo "  CLI_ALLOWED_USERS=root,http,user1  - Comma-separated list of allowed users\n\n";
    echo "Audit log location: {$auditLogFile}\n";

    exit(0);
}

// Validate users file exists or can be created (only for non-help commands)
if (!file_exists($usersFile)) {
    $configDir = dirname($usersFile);
    if (!is_dir($configDir)) {
        mkdir($configDir, 0755, true);
    }
    // Initialize empty users file
    $lockFile = $usersFile . '.lock';
    $lockHandle = fopen($lockFile, 'c');
    if ($lockHandle === false || !flock($lockHandle, LOCK_EX)) {
        if (is_resource($lockHandle)) {
            fclose($lockHandle);
        }
        echo "❌ Failed to lock users file for initialization: {$usersFile}\n";

        exit(1);
    }
    if (!file_exists($usersFile)) {
        $tempFile = $usersFile . '.' . getmypid() . '.tmp';
        if (file_put_contents($tempFile, '[]') === false || !rename($tempFile, $usersFile)) {
            @unlink($tempFile);
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
            echo "❌ Failed to create users file: {$usersFile}\n";

            exit(1);
        }
        chmod($usersFile, 0600);
        echo "✨ Created new users file: {$usersFile}\n\n";
    }
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
} elseif (!is_readable($usersFile) || !is_writable($usersFile)) {
    // File exists but we don't have permission
    $owner = posix_getpwuid(fileowner($usersFile));

    echo "❌ Permission denied: {$usersFile}\n";
    echo "   File owner: {$owner['name']}\n";
    echo "   Current user: {$currentUser['name']}\n\n";
    echo "Fix this by running ONE of the following:\n\n";
    echo "  Option 1 - Run as file owner:\n";
    echo "    sudo -u {$owner['name']} php {$scriptPath}" . ($env !== 'dev' ? " --env={$env}" : '') . "\n\n";
    echo "  Option 2 - Change file ownership (development only):\n";
    echo "    sudo chown {$currentUser['name']}:{$currentUser['name']} {$usersFile}\n\n";

    exit(1);
}

// Create audit logger for CLI operations
$logger = new Logger('user-management-cli');

// Configure formatter for detailed audit trail
$formatter = new LineFormatter(
    "[%datetime%] %level_name%: %message% %context%\n",
    'Y-m-d H:i:s',
    true,
    true
);

$handler = new StreamHandler($auditLogFile, Logger::INFO);
$handler->setFormatter($formatter);
$logger->pushHandler($handler);

// Log script execution
$logger->info('User management script started', [
    'operator' => $currentUser['name'],
    'environment' => $env,
    'action' => $action,
    'hostname' => gethostname(),
    'pid' => getmypid(),
]);

$auth = new Auth(
    logger: $logger,
    usersFile: $usersFile,
    secureRequest: false,
    rememberToken: null,
    cliMode: true,
    cliScriptPath: $scriptPath
);

echo "🔧 Environment: {$env}\n";
echo "📁 Users file: {$usersFile}\n\n";

switch ($action) {
    case 'create':
        $username = $arguments[2] ?? readline('Username: ');
        echo 'Password: ';
        system('stty -echo');
        $password = trim(fgets(STDIN));
        system('stty echo');
        echo "\n";
        $role = $arguments[3] ?? readline('Role [viewer/admin]: ');

        try {
            $auth->createUser($username, $password, $role);
            $logger->info('User created successfully', [
                'operator' => $currentUser['name'],
                'target_user' => $username,
                'role' => $role,
                'environment' => $env,
            ]);
            echo "✅ User created: {$username} (role: {$role})\n";
        } catch (Exception $e) {
            $logger->error('Failed to create user', [
                'operator' => $currentUser['name'],
                'target_user' => $username,
                'role' => $role,
                'error' => $e->getMessage(),
                'environment' => $env,
            ]);
            echo '❌ Error: ' . $e->getMessage() . "\n";

            exit(1);
        }

        break;

    case 'list':
        $users = $auth->getAllUsers();
        $logger->info('Users list accessed', [
            'operator' => $currentUser['name'],
            'environment' => $env,
            'user_count' => count($users),
        ]);
        if (empty($users)) {
            echo "ℹ️  No users found\n";
        } else {
            echo "Users:\n";
            foreach ($users as $user) {
                $locked = ($user['locked_until'] ?? 0) > time() ? ' [LOCKED]' : '';
                echo "  - {$user['username']} ({$user['role']}){$locked}\n";
            }
        }

        break;

    case 'unlock':
        $username = $arguments[2] ?? readline('Username to unlock: ');

        try {
            $auth->unlockUser($username);
            $logger->info('User unlocked successfully', [
                'operator' => $currentUser['name'],
                'target_user' => $username,
                'environment' => $env,
            ]);
            echo "✅ User unlocked: {$username}\n";
        } catch (Exception $e) {
            $logger->error('Failed to unlock user', [
                'operator' => $currentUser['name'],
                'target_user' => $username,
                'error' => $e->getMessage(),
                'environment' => $env,
            ]);
            echo '❌ Error: ' . $e->getMessage() . "\n";

            exit(1);
        }

        break;

    case 'delete':
        $username = $arguments[2] ?? readline('Username to delete: ');
        $confirm = $arguments[3] ?? readline('Are you sure? (yes/no): ');
        if ($confirm === 'yes') {
            try {
                $auth->deleteUser($username);
                $logger->warning('User deleted', [
                    'operator' => $currentUser['name'],
                    'target_user' => $username,
                    'environment' => $env,
                ]);
                echo "✅ User deleted: {$username}\n";
            } catch (Exception $e) {
                $logger->error('Failed to delete user', [
                    'operator' => $currentUser['name'],
                    'target_user' => $username,
                    'error' => $e->getMessage(),
                    'environment' => $env,
                ]);
                echo '❌ Error: ' . $e->getMessage() . "\n";

                exit(1);
            }
        } else {
            $logger->info('User deletion cancelled', [
                'operator' => $currentUser['name'],
                'target_user' => $username,
                'environment' => $env,
            ]);
            echo "❌ Cancelled\n";
        }

        break;

    case 'passwd':
        $username = $arguments[2] ?? readline('Username: ');
        echo 'New password: ';
        system('stty -echo');
        $password = trim(fgets(STDIN));
        system('stty echo');
        echo "\n";

        try {
            $auth->changePassword($username, $password);
            $logger->warning('Password changed', [
                'operator' => $currentUser['name'],
                'target_user' => $username,
                'environment' => $env,
            ]);
            echo "✅ Password changed for: {$username}\n";
        } catch (Exception $e) {
            $logger->error('Failed to change password', [
                'operator' => $currentUser['name'],
                'target_user' => $username,
                'error' => $e->getMessage(),
                'environment' => $env,
            ]);
            echo '❌ Error: ' . $e->getMessage() . "\n";

            exit(1);
        }

        break;

    default:
        echo "❌ Unknown action: {$action}\n\n";
        echo "Run 'php manage-users.php help' for usage information.\n";

        exit(1);
}
