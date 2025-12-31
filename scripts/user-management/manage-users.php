#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Monolog\Handler\NullHandler;
use Monolog\Logger;
use Watcher\Core\Auth;

// Determine environment (default to 'dev' if not set)
$env = getenv('APP_ENV') ?: 'dev';

// Allow override via --env flag
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--env=')) {
        $env = substr($arg, 6);
    }
}

$usersFile = __DIR__ . "/../../config/{$env}/users.json";

// Validate users file exists or can be created
if (!file_exists($usersFile)) {
    $configDir = dirname($usersFile);
    if (!is_dir($configDir)) {
        mkdir($configDir, 0755, true);
    }
    // Initialize empty users file
    file_put_contents($usersFile, '[]');
    chmod($usersFile, 0600);
}

// Create auth with null logger for CLI
$logger = new Logger('cli');
$logger->pushHandler(new NullHandler());
$auth = new Auth($logger, $usersFile);
$_SESSION['user']['role'] = 'admin';

$action = $argv[1] ?? 'help';

// Remove --env flag from argv if present
$argv = array_filter($argv, fn($arg) => !str_starts_with($arg, '--env='));
$argv = array_values($argv);

echo "🔧 Environment: {$env}\n";
echo "📁 Users file: {$usersFile}\n\n";

switch ($action) {
    case 'create':
        $username = $argv[2] ?? readline('Username: ');
        echo 'Password: ';
        system('stty -echo');
        $password = trim(fgets(STDIN));
        system('stty echo');
        echo "\n";
        $role = $argv[3] ?? readline('Role [viewer/admin]: ');

        try {
            $auth->createUser($username, $password, $role);
            echo "✅ User created: {$username} (role: {$role})\n";
        } catch (Exception $e) {
            echo '❌ Error: ' . $e->getMessage() . "\n";
            exit(1);
        }

        break;

    case 'list':
        $users = $auth->getAllUsers();
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
        $username = $argv[2] ?? readline('Username to unlock: ');

        try {
            $auth->unlockUser($username);
            echo "✅ User unlocked: {$username}\n";
        } catch (Exception $e) {
            echo '❌ Error: ' . $e->getMessage() . "\n";
            exit(1);
        }

        break;

    case 'delete':
        $username = $argv[2] ?? readline('Username to delete: ');
        $confirm = $argv[3] ?? readline('Are you sure? (yes/no): ');
        if ($confirm === 'yes') {
            try {
                $auth->deleteUser($username);
                echo "✅ User deleted: {$username}\n";
            } catch (Exception $e) {
                echo '❌ Error: ' . $e->getMessage() . "\n";
                exit(1);
            }
        } else {
            echo "❌ Cancelled\n";
        }

        break;

    case 'passwd':
        $username = $argv[2] ?? readline('Username: ');
        echo 'New password: ';
        system('stty -echo');
        $password = trim(fgets(STDIN));
        system('stty echo');
        echo "\n";

        try {
            $auth->changePassword($username, $password);
            echo "✅ Password changed for: {$username}\n";
        } catch (Exception $e) {
            echo '❌ Error: ' . $e->getMessage() . "\n";
            exit(1);
        }

        break;

    default:
        echo "Usage: php manage-users.php [action] [options] [--env=ENV]\n\n";
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
        echo "  php manage-users.php passwd admin --env=prod\n";
        exit(0);
}
