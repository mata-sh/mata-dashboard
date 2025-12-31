#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Unified lightweight migration wrapper for Phinx.
 *
 * Usage examples:
 *   php scripts/migrate.php migrate --env=staging
 *   php scripts/migrate.php rollback --env=prod
 *   php scripts/migrate.php status --docker
 *   php scripts/migrate.php migrate --env=prod --container=my_php
 */

require __DIR__ . '/../../vendor/autoload.php';

// --- Load your application environment ---
$bootstrap = require __DIR__ . '/../bootstrap.php';
['appConfig' => $appConfig, 'paths' => $paths] = $bootstrap;

// --- CLI argument parsing ---
array_shift($argv); // remove script name

$env = 'development';
$useDocker = false;
$dockerContainer = 'php_app'; // default container name
$phinxArgs = [];

// parse args
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--env=')) {
        $env = substr($arg, 6);
    } elseif ($arg === '--docker') {
        $useDocker = true;
    } elseif (str_starts_with($arg, '--container=')) {
        $useDocker = true;
        $dockerContainer = substr($arg, 12);
    } else {
        $phinxArgs[] = $arg;
    }
}

// --- Build the dynamic Phinx config ---
$phinxConfig = [
    'paths' => [
        'migrations' => $paths->appPath . '/migrations',
        'seeds' => $paths->appPath . '/seeds',
    ],
    'environments' => [
        'default_migration_table' => 'phinxlog',
        'default_environment' => $env,
        $env => [
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

// write temporary config file
$tempConfig = sys_get_temp_dir() . '/phinx.' . uniqid('', true) . '.php';
file_put_contents($tempConfig, '<?php return ' . var_export($phinxConfig, true) . ';');

// --- Build Phinx command ---
$cmd = sprintf(
    'vendor/bin/phinx %s -c %s',
    implode(' ', array_map('escapeshellarg', $phinxArgs)),
    // escapeshellarg($env),
    escapeshellarg($tempConfig)
);

// --- Optional Docker passthrough ---
if ($useDocker) {
    $cmd = sprintf(
        'docker exec -it %s %s',
        escapeshellarg($dockerContainer),
        $cmd
    );
}

// --- Execute ---
echo "🔧 Running: {$cmd}\n\n";
passthru($cmd, $exitCode);

// --- Cleanup ---
@unlink($tempConfig);

exit($exitCode);
