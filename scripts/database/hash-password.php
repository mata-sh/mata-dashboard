#!/usr/bin/env php
<?php

declare(strict_types=1);

echo "Password Hash Generator\n";
echo "======================\n\n";

echo 'Enter password: ';
system('stty -echo');
$input = fgets(STDIN);
system('stty echo');
echo "\n";

if ($input === false) {
    fwrite(STDERR, "ERROR: No password received on STDIN.\n");

    exit(1);
}

$password = trim($input);

$hash = password_hash($password, PASSWORD_DEFAULT);

echo "\nPassword hash:\n";
echo $hash . "\n\n";

echo "Add this to your users.json:\n";
echo json_encode([
    'id' => uniqid('user_'),
    'username' => 'your_username',
    'password_hash' => $hash,
    'auth_revision' => bin2hex(random_bytes(32)),
    'role' => 'admin',
    'created_at' => date('c'),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
