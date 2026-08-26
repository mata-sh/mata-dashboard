#!/usr/bin/php8.2
<?php

declare(strict_types=1);

use Mata\Bootstrap\CronRuntime;

require_once __DIR__ . '/../vendor/autoload.php';

$runtime = CronRuntime::boot(__FILE__);
$runtime->requireDatabase();
$db = $runtime->db;

$rows = $db->runQuery(<<<'SQL'
SELECT 'raw' AS tier, COUNT(*) AS row_count, MAX(collected_at) AS latest FROM server_metrics_raw
UNION ALL
SELECT 'hourly' AS tier, COUNT(*) AS row_count, MAX(hour_timestamp) AS latest FROM server_metrics_hourly
UNION ALL
SELECT 'daily' AS tier, COUNT(*) AS row_count, MAX(date) AS latest FROM server_metrics_daily
SQL);

// runQuery() returns int for write statements; a SELECT always yields rows.
if (!is_array($rows)) {
    fwrite(STDERR, "ERROR: Unexpected result type from the metrics tier query.\n");

    exit(1);
}

foreach ($rows as $row) {
    printf("%-6s rows=%d latest=%s\n", $row['tier'], $row['row_count'], $row['latest'] ?? 'none');
}
