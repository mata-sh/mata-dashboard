#!/usr/bin/env php
<?php

declare(strict_types=1);

// scripts/migrate-passwords.php

$usersFile = __DIR__ . '/../../config/users.json';
$users = json_decode(file_get_contents($usersFile), true);

$migrated = [];
foreach ($users as $username => $password) {
    // If it's already an array with password_hash, skip
    if (is_array($password) && isset($password['password_hash'])) {
        $migrated[] = $password;

        continue;
    }

    // Migrate plain text password
    $migrated[] = [
        'id' => uniqid('usr_'),
        'username' => $username,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'role' => 'admin',
        'created_at' => date('c'),
    ];

    echo "Migrated user: {$username}\n";
}

file_put_contents($usersFile, json_encode($migrated, JSON_PRETTY_PRINT));
chmod($usersFile, 0600);

echo "\n✅ Migration complete. Old passwords have been hashed.\n";
echo "⚠️  Users will need to use their original passwords to log in.\n";
