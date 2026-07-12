#!/usr/bin/php8.2
<?php

declare(strict_types=1);

$requiresDatabase = true;
extract(require_once __DIR__ . '/../cron/bootstrap.php');

$rows = $db->runQuery(<<<'SQL'
SELECT 'raw' AS tier, COUNT(*) AS row_count, MAX(collected_at) AS latest FROM server_metrics_raw
UNION ALL
SELECT 'hourly' AS tier, COUNT(*) AS row_count, MAX(hour_timestamp) AS latest FROM server_metrics_hourly
UNION ALL
SELECT 'daily' AS tier, COUNT(*) AS row_count, MAX(date) AS latest FROM server_metrics_daily
SQL);

foreach ($rows as $row) {
    printf("%-6s rows=%d latest=%s\n", $row['tier'], $row['row_count'], $row['latest'] ?? 'none');
}
