#!/usr/bin/env php
<?php

declare(strict_types=1);

use Mata\Config\EnvConfigLoader;

require_once __DIR__ . '/../../vendor/autoload.php';

$root = dirname(__DIR__, 2);
$operation = $argv[1] ?? '';
$file = $argv[2] ?? null;

if (!in_array($operation, ['backup', 'restore', 'shell'], true)) {
    fwrite(STDERR, "Usage: php scripts/database/mysql-operation.php backup [FILE]|restore FILE|shell\n");

    exit(2);
}

$loader = new EnvConfigLoader();
$config = $loader->load(
    ['config.env', 'db.config.env', 'mail.config.env'],
    $root . '/config'
);
$db = $config->database;

/**
 * Resolve an executable by name from PATH, aborting when it is missing.
 *
 * Declared as named functions rather than closures: PHPStan only applies
 * `@param` PHPDoc to named functions, and `runClient()` needs `list<string>`
 * to satisfy proc_open().
 */
function findExecutable(string $name): string
{
    $path = getenv('PATH') ?: '';
    foreach (explode(PATH_SEPARATOR, $path) as $directory) {
        $candidate = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $name;
        if (is_file($candidate) && is_executable($candidate)) {
            return $candidate;
        }
    }

    fwrite(STDERR, "ERROR: {$name} is not available in PATH.\n");

    exit(1);
}

/**
 * @param list<string>         $command     Executable followed by its arguments
 * @param array<int,resource>  $descriptors File descriptor map for proc_open()
 * @param array<string,string> $environment Environment for the child process
 */
function runClient(array $command, array $descriptors, array $environment): int
{
    $process = proc_open($command, $descriptors, $pipes, null, $environment);
    if (!is_resource($process)) {
        fwrite(STDERR, "ERROR: Failed to start database client.\n");

        return 1;
    }

    return proc_close($process);
}

$environment = getenv();
if (!is_array($environment)) {
    $environment = [];
}
$environment['MYSQL_PWD'] = $db->password;

$connectionArgs = [
    '--host=' . $db->host,
    '--port=' . $db->port,
    '--user=' . $db->username,
];

if ($operation === 'backup') {
    $binary = findExecutable('mysqldump');
    $destination = $file ?: $root . '/backups/backup-' . date('Ymd-His') . '.sql';
    if (!str_starts_with($destination, DIRECTORY_SEPARATOR)) {
        $destination = $root . '/' . ltrim($destination, './');
    }

    $directory = dirname($destination);
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
        fwrite(STDERR, "ERROR: Cannot create backup directory: {$directory}\n");

        exit(1);
    }

    $temporary = $destination . '.' . getmypid() . '.tmp';
    $output = fopen($temporary, 'xb');
    if ($output === false) {
        fwrite(STDERR, "ERROR: Cannot create temporary backup: {$temporary}\n");

        exit(1);
    }
    chmod($temporary, 0600);

    $command = array_merge(
        [$binary],
        $connectionArgs,
        ['--single-transaction', '--quick', '--skip-lock-tables', '--no-tablespaces', $db->database]
    );
    $status = runClient($command, [0 => STDIN, 1 => $output, 2 => STDERR], $environment);
    fclose($output);

    if ($status !== 0) {
        @unlink($temporary);
        fwrite(STDERR, "ERROR: Database backup failed.\n");

        exit($status);
    }
    if (!rename($temporary, $destination)) {
        @unlink($temporary);
        fwrite(STDERR, "ERROR: Cannot finalize backup: {$destination}\n");

        exit(1);
    }

    echo "Backup saved: {$destination}\n";

    exit(0);
}

if ($operation === 'restore') {
    if ($file === null || $file === '') {
        fwrite(STDERR, "ERROR: restore requires a backup file.\n");

        exit(2);
    }
    $source = str_starts_with($file, DIRECTORY_SEPARATOR) ? $file : $root . '/' . ltrim($file, './');
    if (!is_file($source) || !is_readable($source) || filesize($source) === 0) {
        fwrite(STDERR, "ERROR: Backup must be a non-empty readable file: {$source}\n");

        exit(1);
    }

    $binary = findExecutable('mysql');
    $input = fopen($source, 'rb');
    if ($input === false) {
        fwrite(STDERR, "ERROR: Cannot open backup: {$source}\n");

        exit(1);
    }
    $command = array_merge([$binary], $connectionArgs, [$db->database]);
    $status = runClient($command, [0 => $input, 1 => STDOUT, 2 => STDERR], $environment);
    fclose($input);

    exit($status);
}

$binary = findExecutable('mysql');
$command = array_merge([$binary], $connectionArgs, [$db->database]);

exit(runClient($command, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $environment));
