<?php

declare(strict_types=1);

use Watcher\Config\EnvConfigLoader;
use Watcher\Core\Database;
use Watcher\Utils\PathResolver;

require_once __DIR__ . '/../vendor/autoload.php';

// --- Path setup ---
$paths = new PathResolver(__FILE__);

// --- Error reporting ---
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// --- Config loading ---
$configLoader = new EnvConfigLoader();
$appConfig = $configLoader->load([
    'config.env',
    'db.config.env',
    'mail.config.env',
], $paths->appPath . '/config');

$validationResult = $configLoader->validateConfig($appConfig);
if (!$validationResult['is_valid']) {
    foreach ($validationResult['critical_errors'] as $error) {
        fwrite(STDERR, "[CONFIG ERROR] {$error['identifier']}: {$error['message']}\n");
    }

    exit(1);
}

// --- Database ---
$db = new Database($appConfig->database);

// --- Optional: shared tool instances ---
return compact('paths', 'appConfig', 'db');
