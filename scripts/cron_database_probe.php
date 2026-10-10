#!/usr/bin/php8.2
<?php

declare(strict_types=1);

use Mata\Bootstrap\CronRuntime;
use Mata\Database\DatabaseIntegrityChecker;

require_once __DIR__ . '/../vendor/autoload.php';

$runtime = CronRuntime::boot(__FILE__);
$runtime->requireDatabase();
$cron = $runtime->cron;
$db = $runtime->db;

(new DatabaseIntegrityChecker($db))->assertReady();
$cron->log('Cron database probe succeeded.');
