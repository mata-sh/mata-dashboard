#!/usr/bin/env php
<?php

declare(strict_types=1);

use Mata\Bootstrap\ScriptRuntime;
use Mata\Database\DatabaseIntegrityChecker;
use Mata\Database\PdoDatabase;

require __DIR__ . '/../../vendor/autoload.php';

$runtime = ScriptRuntime::boot($_SERVER['argv'] ?? []);

try {
    $db = new PdoDatabase($runtime->appConfig->database);
} catch (Throwable $e) {
    fwrite(STDERR, "❌ Database connection failed: {$e->getMessage()}\n");

    exit(1);
}

$checker = new DatabaseIntegrityChecker($db);
$result = $checker->run();

$overall = $result['status'] === 'ok' ? '✅ Database integrity checks passed' : '❌ Database integrity checks failed';
echo $overall . PHP_EOL;

foreach ($result['checks'] as $check) {
    $statusIcon = $check['status'] === 'ok' ? '  ✓' : '  ✗';
    $details = $check['details'] ?? '';
    if ($details !== '') {
        $details = " ({$details})";
    }
    echo "{$statusIcon} {$check['label']}{$details}" . PHP_EOL;
}

if ($result['status'] !== 'ok') {
    exit(1);
}
