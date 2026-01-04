#!/usr/bin/env php
<?php

declare(strict_types=1);

echo "Password Hash Generator\n";
echo "======================\n\n";

echo 'Enter password: ';
system('stty -echo');
$password = trim(fgets(STDIN));
system('stty echo');
echo "\n";

$hash = password_hash($password, PASSWORD_DEFAULT);

echo "\nPassword hash:\n";
echo $hash . "\n\n";

echo "Add this to your users.json:\n";
echo json_encode([
    'username' => 'your_username',
    'password_hash' => $hash,
    'role' => 'admin',
    'created_at' => date('c'),
], JSON_PRETTY_PRINT) . "\n";
