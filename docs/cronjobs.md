# Cron Jobs Runbook

## Monitoring Ingestion Health
- Check `logs/cron/collect_server_metrics_health.log` after each run for inserts, last sample timestamp, and computed lag per server.
- Stalled servers emit a dashboard event with type `server_metrics.ingestion_stall`.
- Tune stall/retention thresholds in `config/data_retention.json` under `ingestion_health` (default window is 15 minutes).

## Manual Backfill / Rehydration
- Fix the upstream node, then rerun `php cron/servers_metrics_collect.php` to repopulate raw samples.
- If raw samples exist but hourly/daily aggregates are missing, run the targeted backfill below:

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

- When done, run `php cron/servers_metrics_aggregate.php` without overrides to resume normal cadence.
