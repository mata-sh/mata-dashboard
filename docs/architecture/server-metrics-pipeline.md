# Server metrics pipeline

This is the implementation reference for historical server metrics: CPU, RAM,
disk, files, and process counts. All historical timestamps and aggregation
windows are UTC.

## Short version

```text
configured node API
  -> servers_metrics_collect.php (every 5 min)
  -> server_metrics_raw
  -> servers_metrics_aggregate.php (every 15 min)
  -> server_metrics_hourly and server_metrics_daily
  -> /api/metrics/{server}/{metric}
  -> shared server-card modal
```

The current server card and the historical modal are different views:

- The **server card** shows the latest data fetched/cached for the dashboard.
- The **modal** shows rows persisted by the historical pipeline below.
- A current card value does not prove that historical rows exist. A reachable
  node and a successful collection run are required for history.

## Schedules and programs

The installed manifest is
[`docker/cron/crontab`](https://github.com/mata-sh/mata-dashboard/blob/main/docker/cron/crontab).
Cron runs as `www-data` in Docker. Shared-hosting installations can use the
[`shared-hosting crontab template`](https://github.com/mata-sh/mata-dashboard/blob/main/cron/crontab.shared-hosting.template).

| When | Program | Purpose | Writes |
| --- | --- | --- | --- |
| Every 5 min | `cron/servers_data_fetch.php` | Warm the node cache that collection reads, chained before it | not the historical tables |
| Every 5 min | `cron/servers_metrics_collect.php` | Fetch one snapshot per server not marked `disabled` | `server_metrics_raw` |
| Every 15 min | `cron/servers_metrics_aggregate.php` | Repair bounded completed hourly and daily windows | `server_metrics_hourly`, `server_metrics_daily` |
| Daily, 02:25 | `cron/retention_cleanup.php` | Delete old rows according to retention settings | deletes from all metric tiers |

`servers_metrics_collect.php` skips configuration keys beginning with `_` and
servers with `disabled` set to `true`. Each remaining server needs `api_url`
in `servers.json` and its `api_key` in `servers.secrets.json`.

Cron has a restricted environment. Container startup writes only the required
`DB_*` values to `/etc/mata/cron.env`; each cron command sources it before PHP
bootstrap. Check that exact context with:

```bash
docker exec <web-container> mata-cron-smoke-check
```

## 1. Collection: node response to raw snapshot

`cron/servers_metrics_collect.php` creates an authenticated `NodeApiClient`, then
uses `ServerDataCollector` and `ServerMonitor` to fetch server health data from
the configured node API.

`buildSnapshotFromPayload()` maps the node response into
`ServerMetricRawSnapshot`:

| Raw snapshot value | Node response source | Notes |
| --- | --- | --- |
| `cpu_usage` | `cpu_usage` | Percent |
| `ram_used`, `ram_total` | `ram_used` / `ram_capacity`, or RAM/memory alternatives | KiB |
| `ram_usage_percent` | calculated as `ram_used / ram_total * 100` | Null if total is absent or zero |
| `disk_used`, `disk_total` | disk/storage alternatives | KiB |
| `disk_usage_percent` | calculated as `disk_used / disk_total * 100` | Null if total is absent or zero |
| `total_file_count` | `total_system_filecount` or file-system alternatives | Count |
| `process_count`, `php_process_count` | `processes.*` or alternatives | Count |
| `php_version`, `os_version`, `server_uptime` | corresponding node data | Metadata |
| `collected_at` | node timestamp; otherwise cache timestamp adjusted by cache age, then collection time | UTC |
| `ingested_at` | dashboard insertion time | UTC |

The collector identity is read from `data.collector` when present; otherwise it
uses configured fallback values. `ServerMetricsRepository` resolves/creates
`servers.id` and `metric_collectors.id`, normalizes non-negative counts and
0–100 percentages, then uses `ServerMetricRawUpserter` for the database upsert.

Duplicate protection is database-enforced by unique `(server_id, collector_id,
collected_at)` and `(server_id, payload_hash, collected_at)` keys. A duplicate
updates the existing row; it does not add another point.

Collection health is recorded in:

- `logs/cron/app-servers-metrics-collect.log`
- `logs/health/collect_server_metrics_health.log`
- `logs/cron/heartbeat-servers-metrics-collect.jsonl`

The health report contains per-server inserted count, latest sample timestamp,
and ingestion lag.

## 2. Raw table

`server_metrics_raw` is the detailed tier. Expected density is about twelve
points per hour when collection succeeds every five minutes.

| Columns |
| --- |
| `id`, `server_id`, `collector_id` |
| `collected_at`, `ingested_at`, `payload_hash` |
| `cpu_usage` |
| `ram_used`, `ram_total`, `ram_usage_percent` |
| `disk_used`, `disk_total`, `disk_usage_percent` |
| `total_file_count`, `process_count`, `php_process_count` |
| `php_version`, `os_version`, `server_uptime` |

Most metric columns are nullable. `NULL` means the node did not provide that
measurement. It is not equivalent to zero.

## 3. Aggregation

`cron/servers_metrics_aggregate.php` is orchestration only. It creates:

- `AggregationWindowPlanner`: completed UTC windows.
- `ServerMetricsAggregator`: SQL statistics for one server/window.
- `ServerMetricsRepository`: id resolution and upserts.

### Hourly planning

With the default configuration (`lag_minutes: 5`, `catch_up_hours: 24`), every
15-minute run plans the last 24 **completed** UTC hours. It intentionally
upserts them again. This bounded overlap repairs late-arriving snapshots and
missed aggregation runs.

For an hour starting at `10:00 UTC`, raw rows are selected with:

```text
collected_at >= 10:00:00 UTC
collected_at <  11:00:00 UTC
```

The start is inclusive and end is exclusive. No raw rows means no hourly row;
the job does not write a fake zero aggregate.

Hourly aggregates compute `AVG`, `MIN`, and/or `MAX` for the available metric,
plus `sample_count`, `first_sample`, and `last_sample`.

### Daily planning

Daily planning does not depend on an hourly row being inserted in the same run.
With `lag_minutes: 30` and configured `catch_up_days: 7`, it upserts
recent completed UTC days after the 30-minute post-midnight delay.

A daily aggregate normally reads raw rows for:

```text
collected_at >= <day-start> 00:00:00 UTC
collected_at <  <next-day> 00:00:00 UTC
```

If raw retention has already removed the entire day, it falls back to hourly
rows. Average values use `SUM(hourly_average * sample_count) / SUM(sample_count)`;
it never averages averages without weights. Daily critical-event and warning-event counts are loaded separately from
`events`; they are not counts of logical alarms. If that query fails, metric
values are still saved and the failure is logged.

### Aggregate tables

Both tables use a unique server/window key and `INSERT ... ON DUPLICATE KEY
UPDATE`, so a rerun updates the same row.

| Table | Window key | Columns |
| --- | --- | --- |
| `server_metrics_hourly` | `(server_id, hour_timestamp)` | `cpu_avg/min/max`; `ram_used_avg/min/max`; `ram_percent_avg/max`; `disk_used_avg/min/max`; `disk_percent_avg/max`; `file_count_avg/max`; `process_count_avg/max`; `sample_count`; `first_sample`; `last_sample` |
| `server_metrics_daily` | `(server_id, date)` | The same CPU/RAM/disk/file/process aggregate fields; `sample_count`; `alarm_count`; `warning_count` |

Aggregation logs to `logs/cron/app-servers-metrics-aggregate.log` and writes
`heartbeat-servers-metrics-aggregate.jsonl`.

## 4. Retention

Aggregation does not delete data. `cron/retention_cleanup.php` owns deletion.
Settings are in the active `data_retention.json`; the example is
[`config.example/data_retention.json.example`](https://github.com/mata-sh/mata-dashboard/blob/main/config.example/data_retention.json.example).

Default retention:

| Tier | Default |
| --- | ---: |
| Raw | 30 days |
| Hourly | 90 days |
| Daily | 365 days |

Set a retention value to `0` to disable cleanup for that tier. Do not set raw
retention shorter than the daily aggregation/recovery window unless relying on
the hourly weighted fallback is acceptable.

## 5. Reading and displaying history

### API read path

`MetricsController::getServerMetricSeries()` handles:

```text
GET /api/metrics/{server}/{metric}?range=24h&granularity=hourly
```

1. `ServerMetrics\MetricsRequestValidator` validates HTTP query inputs using
   `MetricCatalog` and shared `MetricQueryPolicy` normalization/range rules.
2. `MetricsCacheService` checks the response cache unless `force=1`.
3. `MetricsViewBuilder` picks `ServerMetricsQuery::getRawMetrics`,
   `getHourlyMetrics`, or `getDailyMetrics`.
4. `ServerMetricsQuery` maps database rows to DTOs.
5. `MetricsViewBuilder` extracts the requested field, labels it in UTC, and
   calculates min, max, and average from non-null values.
6. `MetricsRenderer` returns JSON.

Queries are ordered ascending and use inclusive start/exclusive end boundaries.
A valid request with no rows returns HTTP 200 with empty labels/data. A null
measurement remains JSON `null`.

Supported API metric keys:

| Key | Source by tier | Unit |
| --- | --- | --- |
| `cpu` | `cpu_usage` / `cpu_avg` | `%` |
| `ram_percent` | `ram_usage_percent` / `ram_percent_avg` | `%` |
| `ram_used` | `ram_used` / `ram_used_avg` | KiB |
| `disk_percent` | `disk_usage_percent` / `disk_percent_avg` | `%` |
| `disk_used` | `disk_used` / `disk_used_avg` | KiB |
| `file_count` | `total_file_count` / `file_count_avg` | count |
| `process_count` | `process_count` / `process_count_avg` | count |

### Shared server-card modal

`templates/partials/cards/server-stats.html.twig` turns CPU, RAM, disk, and
file-count card stats into buttons. Each opens:

```text
GET /watch/modal/{server}/metrics?metric=<key>&range=24h
```

`MetricsController::renderServerMetricsModal()` only accepts `cpu`,
`ram_percent`, `disk_percent`, and `file_count`. The route renders
`templates/partials/modals/server-metrics-modal.html.twig` once.

`public/assets/js/server-metrics-modal.js` handles selectors, refresh, request
cancellation, loading/error/empty/partial states, Chart.js lifecycle, and
unit formatting. It fetches the canonical API endpoint when a selector changes.

The modal uses a fixed tier mapping:

| UI range | Table read |
| --- | --- |
| 6 hours | `server_metrics_raw` |
| 24 hours | `server_metrics_hourly` |
| 7 days | `server_metrics_hourly` |
| 30 days | `server_metrics_daily` |

Process count deliberately remains a non-interactive card statistic.

## Operations and diagnosis

```bash
# Row count and latest timestamp for each tier
make mata ARGS="metrics status"

# Verify cron-user config and database bootstrap, inside the web container
mata-cron-smoke-check
```

For per-server detail, use SQL:

```sql
SELECT s.name, COUNT(*) AS raw_rows, MAX(r.collected_at) AS latest_raw
FROM servers s
LEFT JOIN server_metrics_raw r ON r.server_id = s.id
GROUP BY s.id, s.name
ORDER BY s.name;

SELECT s.name,
       MAX(h.hour_timestamp) AS latest_hourly,
       MAX(d.date) AS latest_daily
FROM servers s
LEFT JOIN server_metrics_hourly h ON h.server_id = s.id
LEFT JOIN server_metrics_daily d ON d.server_id = s.id
GROUP BY s.id, s.name
ORDER BY s.name;
```

If raw rows stay at zero, inspect collection logs first. Common causes are a
disabled server configuration, missing `api_url`/`api_key`, node DNS/network
failure, node authentication failure, or cron database bootstrap failure.
