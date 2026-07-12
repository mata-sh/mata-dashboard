#!/usr/bin/php8.2
<?php

declare(strict_types=1);

use Mata\Config\DatabaseIntegrityChecker;

$requiresDatabase = true;
extract(require_once __DIR__ . '/../cron/bootstrap.php');

(new DatabaseIntegrityChecker($db))->assertReady();
$cron->log('Cron database probe succeeded.');
