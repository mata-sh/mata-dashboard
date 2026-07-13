#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Lightweight Phinx wrapper.
 *
 * Usage:
 *   APP_ENV=dev php scripts/database/migrate.php migrate --env=dev
 *   APP_ENV=prod php scripts/database/migrate.php rollback --env=prod
 *   APP_ENV=prod php scripts/database/migrate.php status --env=prod
 */

require __DIR__ . '/../../vendor/autoload.php';

$bootstrap = require __DIR__ . '/../bootstrap.php';
['appConfig' => $appConfig, 'paths' => $paths] = $bootstrap;

$arguments = $_SERVER['argv'] ?? [];
array_shift($arguments);
$env = $appConfig->environment->env;
$phinxArgs = [];

foreach ($arguments as $arg) {
    if (str_starts_with($arg, '--env=')) {
        $env = substr($arg, 6);
    } else {
        $phinxArgs[] = $arg;
    }
}

if (!in_array($env, ['dev', 'demo', 'prod'], true)) {
    fwrite(STDERR, "ERROR: Environment must be dev, demo, or prod.\n");

    exit(2);
}

$action = $phinxArgs[0] ?? '';
if (!in_array($action, ['migrate', 'rollback', 'status'], true)) {
    fwrite(STDERR, "Usage: php scripts/database/migrate.php migrate|rollback|status [--env=dev|demo|prod]\n");

    exit(2);
}

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

$tempConfig = tempnam(sys_get_temp_dir(), 'mata-phinx.');
if ($tempConfig === false) {
    fwrite(STDERR, "ERROR: Cannot create temporary Phinx configuration.\n");

    exit(1);
}
chmod($tempConfig, 0600);
file_put_contents($tempConfig, '<?php return ' . var_export($phinxConfig, true) . ';');

$command = [
    $paths->appPath . '/vendor/bin/phinx',
    ...$phinxArgs,
    '-c',
    $tempConfig,
];

echo "Running database {$action} for {$env}.\n\n";
$process = proc_open($command, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes);
if (!is_resource($process)) {
    @unlink($tempConfig);
    fwrite(STDERR, "ERROR: Failed to start Phinx.\n");

    exit(1);
}

$exitCode = proc_close($process);
@unlink($tempConfig);

exit($exitCode);
