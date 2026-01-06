<?php

declare(strict_types=1);

use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Watcher\Config\EnvConfigLoader;
use Watcher\Core\Database;
use Watcher\Utils\CronJob;
use Watcher\Utils\PathResolver;
use Watcher\Utils\Tools;

// ************************************************************************************************
// Composer
// ************************************************************************************************
require_once __DIR__ . '/../vendor/autoload.php';

// ************************************************************************************************
// Path Initialization
// ************************************************************************************************
$paths = new PathResolver(__FILE__);

// ************************************************************************************************
// Monolog
// ************************************************************************************************
$dateFormat = 'Y-m-d H:i:s';
$output = "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n";
$formatter = new LineFormatter($output, $dateFormat, true, true);
$handler = new StreamHandler($paths->logsPath . '/cron/cronjob.log', Level::Debug);
$handler->setFormatter($formatter);
$log = new Logger('cronjob');
$log->pushHandler($handler);


// ************************************************************************************************
// Error Reporting & Logging
// ************************************************************************************************
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', $paths->logsPath . '/cron/phperror.log');

// ************************************************************************************************
// Cronjob + Config
// ************************************************************************************************
$cron = new CronJob(
    jobName: basename(__FILE__, '.php'),
    logDir: $paths->logsPath . '/cron',
    lockDir: $paths->tempPath . '/',
    heartbeatFile: $paths->logsPath . '/cron/cron_heartbeat.txt',
);

try {
    $configLoader = new EnvConfigLoader();
    $appConfig = $configLoader->load([
        'config.env',
        'db.config.env',
        'mail.config.env',
    ], $paths->appPath . '/config');

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
// Tools & Database
// ************************************************************************************************
$db = new Database($appConfig->database);
$tools = new Tools($paths->appPath);

// ************************************************************************************************
// Mailer
// ************************************************************************************************
$transport = Transport::fromDsn($appConfig->mail->dsn);
$mailer = new Mailer($transport);

return compact('paths', 'log', 'cron', 'appConfig', 'db', 'tools', 'mailer');
