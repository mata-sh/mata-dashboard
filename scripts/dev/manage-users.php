#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Monolog\Handler\NullHandler;
use Monolog\Logger;
use Watcher\Core\Auth;

// Create auth with null logger for CLI
$logger = new Logger('cli');
$logger->pushHandler(new NullHandler());
$auth = new Auth($logger, __DIR__ . '/../../config/demo/users.json');
$_SESSION['user']['role'] = 'admin';

$action = $argv[1] ?? 'help';

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
            echo "✅ User created: {$username}\n";
        } catch (Exception $e) {
            echo '❌ Error: ' . $e->getMessage() . "\n";
        }

        break;

    case 'list':
        $users = $auth->getAllUsers();
        echo "Users:\n";
        foreach ($users as $user) {
            $locked = $user['locked_until'] > time() ? ' [LOCKED]' : '';
            echo "  - {$user['username']} ({$user['role']}){$locked}\n";
        }

        break;

    case 'unlock':
        $username = $argv[2] ?? readline('Username to unlock: ');

        try {
            $auth->unlockUser($username);
            echo "✅ User unlocked: {$username}\n";
        } catch (Exception $e) {
            echo '❌ Error: ' . $e->getMessage() . "\n";
        }

        break;

    case 'delete':
        $username = $argv[2] ?? readline('Username to delete: ');
        $confirm = readline('Are you sure? (yes/no): ');
        if ($confirm === 'yes') {
            try {
                $auth->deleteUser($username);
                echo "✅ User deleted: {$username}\n";
            } catch (Exception $e) {
                echo '❌ Error: ' . $e->getMessage() . "\n";
            }
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
        }

        break;

    default:
        echo "Usage: php manage-users.php [action] [options]\n";
        echo "Actions:\n";
        echo "  create [username] [role]  - Create new user\n";
        echo "  list                       - List all users\n";
        echo "  unlock [username]          - Unlock user account\n";
        echo "  delete [username]          - Delete user\n";
        echo "  passwd [username]          - Change password\n";
}
