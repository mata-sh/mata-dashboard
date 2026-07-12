# Cron Jobs Runbook

`docker/cron/crontab` is the production cron manifest. Docker installs it as the
`www-data` user crontab; entries do not contain a username. Container startup
materializes only required database settings in `/etc/mata/cron.env` (root-owned,
`www-data` group-readable), then every PHP job sources it. Run
`mata-cron-smoke-check` inside the web container to verify bootstrap and DB access
as `www-data`.

## Schedule

| Schedule | Script | Execution log | Heartbeat history |
|----------|--------|---------------|-------------------|
| `*/5 * * * *` | `servers_data_fetch.php` | `logs/cron/app-servers-data-fetch.log` | `logs/cron/heartbeat-servers-data-fetch.jsonl` |
| `*/5 * * * *` | `servers_metrics_collect.php` | `logs/cron/app-servers-metrics-collect.log` | `logs/cron/heartbeat-servers-metrics-collect.jsonl` |
| `*/15 * * * *` | `servers_metrics_aggregate.php` | `logs/cron/app-servers-metrics-aggregate.log` | `logs/cron/heartbeat-servers-metrics-aggregate.jsonl` |
| `* * * * *` | `alarms_evaluate_critical.php` | `logs/cron/app-alarms-evaluate-critical.log` | `logs/cron/heartbeat-alarms-evaluate-critical.jsonl` |
| `*/5 * * * *` | `alarms_evaluate_non_critical.php` | `logs/cron/app-alarms-evaluate-non-critical.log` | `logs/cron/heartbeat-alarms-evaluate-non-critical.jsonl` |
| `* * * * *` | `alarms_dispatch.php` | `logs/cron/app-alarms-dispatch.log` | `logs/cron/heartbeat-alarms-dispatch.jsonl` |
| `25 2 * * *` | `retention_cleanup.php` | `logs/cron/app-retention-cleanup.log` | `logs/cron/heartbeat-retention-cleanup.jsonl` |

## Logs and inspection

Inspect retention tiers and latest UTC timestamps:

```bash
docker exec <web-container> php /var/www/html/scripts/server_metrics_diagnostic.php
```


Execution logs also receive launcher stdout and stderr, including failures before
PHP bootstrap configures logging. PHP runtime errors are written to
`logs/cron/php-error.log`. Heartbeats are JSONL execution history; they are not
removed by cron log maintenance.

`logs/cron/collect_server_metrics_health.log` is a separate ingestion-health
report written only by `servers_metrics_collect.php`. It is not that job's
execution log and has application-managed rotation.

```bash
# Docker: inspect the installed www-data manifest
docker compose exec web crontab -l

# Follow collection execution and ingestion health
tail -f logs/cron/app-servers-metrics-collect.log
tail -f logs/cron/collect_server_metrics_health.log
```

Cron removes active cron logs and their compressed archives after 30 days, and
compresses active logs weekly. It excludes the ingestion-health report from shell
rotation.

For host or native installations, substitute the application root and PHP path
in the manifest while retaining the schedules and `logs/cron/` destinations.

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
