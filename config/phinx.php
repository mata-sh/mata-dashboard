<?php

declare(strict_types=1);

use Mata\Bootstrap\ScriptRuntime;

require __DIR__ . '/../vendor/autoload.php';

$runtime = ScriptRuntime::boot($_SERVER['argv'] ?? []);
$appConfig = $runtime->appConfig;
$paths = $runtime->paths;

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
            'port' => $appConfig->database->port,
            'charset' => 'utf8mb4',
        ],
    ],
    'version_order' => 'creation',
];
