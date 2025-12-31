# Cron Operations Runbook

## Monitoring Ingestion Health
- Review `logs/cron/collect_server_metrics_health.log` after each run; every line captures inserted raw counts, last sample timestamp, and computed lag per server.
- Stalled servers (no new rows for the configured stall window) are also emitted to the dashboard event log under the `server_metrics.ingestion_stall` type.
- Rotate retention or stall thresholds via `config/data_retention.json` → `ingestion_health` block if the default 15 minute window is too strict or lenient.

## Manual Backfill / Rehydration
- When the health log reports a stall, first resolve the upstream node issue and rerun `php cron/collect_server_metrics.php` to repopulate raw samples.
- If raw samples already exist but hourly/daily aggregates remain empty, execute the targeted backfill below, which reuses the existing repository and aggregator classes:

```bash
SERVER_METRICS_SERVER=frontend-1 \
SERVER_METRICS_START='2024-04-18T04:00:00' \
SERVER_METRICS_END='2024-04-18T12:00:00' \
php -r '
require __DIR__ . "/../vendor/autoload.php";
$bootstrap = require __DIR__ . "/bootstrap.php";
$repository = new Watcher\Models\ServerMetrics\ServerMetricsRepository($db);
$aggregator = new Watcher\Models\ServerMetrics\ServerMetricsAggregator($db, $repository, $log);

$server = getenv("SERVER_METRICS_SERVER");
$start = getenv("SERVER_METRICS_START");
$end = getenv("SERVER_METRICS_END");
if (!$server || !$start || !$end) {
    fwrite(STDERR, "SERVER_METRICS_SERVER, SERVER_METRICS_START, SERVER_METRICS_END must be set\n");
    exit(1);
}

$cursor = new DateTimeImmutable($start);
$endHour = new DateTimeImmutable($end);

while ($cursor <= $endHour) {
    $aggregator->aggregateHour($server, $cursor);
    $cursor = $cursor->add(new DateInterval("PT1H"));
}

$aggregator->aggregateDay($server, (new DateTimeImmutable($end))->setTime(0, 0));
'
```

- After aggregates are rebuilt, rerun `php cron/aggregate_server_metrics.php` without overrides to let the daily pruning task resume normal cadence.
