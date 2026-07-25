<?php

declare(strict_types=1);

use Mata\Config\DatabaseIntegrityChecker;
use Mata\Config\EnvConfigLoader;
use Mata\Config\LogConfigurator;
use Mata\Core\Database;
use Mata\Core\NullDatabase;
use Mata\Services\Cron\CronJob;
use Mata\Utils\PathResolver;
use Mata\Utils\Tools;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;

// ************************************************************************************************
// Composer
// ************************************************************************************************
require_once __DIR__ . '/../vendor/autoload.php';

// ************************************************************************************************
// Path Initialization
// ************************************************************************************************
$paths = new PathResolver(__FILE__);

// ************************************************************************************************
// Error Reporting & Logging
// ************************************************************************************************
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', $paths->logsPath . '/cron/php-error.log');

// ************************************************************************************************
// Tools
// ************************************************************************************************
$tools = new Tools($paths->appPath);

// ************************************************************************************************
// Cronjob Name
// ************************************************************************************************
$jobName = $tools->normalizeJobName(basename($_SERVER['SCRIPT_FILENAME'], '.php'));

// ************************************************************************************************
// Monolog
// ************************************************************************************************
$logConfigurator = new LogConfigurator();
$log = $logConfigurator->createCronBootLogger($paths, $jobName);

try {
    $configLoader = new EnvConfigLoader();
    $appConfig = $configLoader->load([
        'config.env',
        'db.config.env',
        'mail.config.env',
    ], $paths->appPath . '/config');
    $log = $logConfigurator->createCronLogger($appConfig->logging, $paths, $jobName);

    $validationResult = $configLoader->validateConfig($appConfig);
    if (!$validationResult['is_valid']) {
        foreach ($validationResult['critical_errors'] as $error) {
            $log->critical("[{$error['identifier']}] {$error['message']}");
        }

        exit('Critical configuration errors!');
    }
} catch (Exception $e) {
    $log->critical('Application Initialization failed! ' . $e->getMessage());

    exit;
}

// ************************************************************************************************
// Database Setup
// ************************************************************************************************
try {
    $db = new Database($appConfig->database);

    $databaseProbe = new DatabaseIntegrityChecker($db);

    try {
        $databaseProbe->assertReady();
    } catch (Throwable $probeException) {
        $log->error('Database quick probe failed; falling back to NullDatabase. ' . $probeException->getMessage());
        $log->debug('Database quick probe failure details', ['exception' => $probeException]);
        $db = new NullDatabase($log);
    }
} catch (Exception $e) {
    $log->error('Failed to initialize DB Connection: ' . $e->getMessage());
    $db = new NullDatabase($log);
}

if (($requiresDatabase ?? false) && $db instanceof NullDatabase) {
    $log->critical('Database required but unavailable. Aborting cronjob.');

    exit(1);
}

// ************************************************************************************************
// Mailer
// ************************************************************************************************
$transport = Transport::fromDsn($appConfig->mail->dsn);
$mailer = new Mailer($transport);

// ************************************************************************************************
// Cronjob
// ************************************************************************************************
$cron = new CronJob(
    logger: $log,
    jobName: $jobName,
    lockDir: $paths->tempPath . '/',
    heartbeatFile: "{$paths->logsPath}/cron/heartbeat-{$jobName}.jsonl",
);

return compact(
    'paths',
    'log',
    'cron',
    'appConfig',
    'db',
    'tools',
    'mailer'
);
