<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// Reuse your app bootstrap
$bootstrap = require __DIR__ . '/../scripts/bootstrap.php';
['appConfig' => $appConfig, 'paths' => $paths] = $bootstrap;

return [
    'paths' => [
        'migrations' => $paths->appPath . '/migrations',
        'seeds' => $paths->appPath . '/seeds',
    ],
    'environments' => [
        'default_migration_table' => 'phinxlog',
        'default_environment' => 'development',
        'development' => [
            'adapter' => 'mysql',
            'host' => $appConfig->database->host,
            'name' => $appConfig->database->database,
            'user' => $appConfig->database->username,
            'pass' => $appConfig->database->password,
            'port' => $appConfig->database->port, // Port not in DatabaseConfig DTO, using default
            'charset' => 'utf8mb4',
        ],
    ],
    'version_order' => 'creation',
];
