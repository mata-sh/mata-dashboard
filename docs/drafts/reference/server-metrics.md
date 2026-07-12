# Server Metrics Reference

This reference covers dashboard server-metric history. It does not define the
node payload; see [Node API Reference](../api/node-api.md). Cron deployment and
logs are in the [Cron Jobs Runbook](../cronjobs.md).

## Current card values versus history

Server cards render current data fetched from the monitored node. CPU, RAM,
disk, and file-count controls open the shared history modal. The process display
is current card data (including PHP/SQL/total detail in its tooltip) and is not
a shared-modal trigger.

Historical charts read the dashboard database. A card value and the newest chart
point can differ because collection is scheduled, source data may be cached, and
hourly/daily charts show aggregate averages rather than a current value.

The historical-series API supports only these keys:

| Key | Raw source / aggregate value | Unit |
| --- | --- | --- |
| `cpu` | CPU usage / average | `%` |
| `ram_percent` | computed RAM used ÷ total / average | `%` |
| `ram_used` | RAM used / average | bytes |
| `disk_percent` | computed disk used ÷ total / average | `%` |
| `disk_used` | disk used / average | bytes |
| `file_count` | total system file count / average | count |
| `process_count` | total process count / average | count |

Load averages, swap, per-type process counts, application sessions, log sizes,
error counts, and inode metrics are not supported historical-series keys. In
particular, PHP process count is retained in raw snapshots but has no chart key;
SQL process count is not persisted by this pipeline.

## Collection and storage

`cron/servers_metrics_collect.php` runs every five minutes. For each configured,
active server with API credentials it fetches server health data, normalizes a
snapshot, and stores it in `server_metrics_raw`. A failed server does not stop
the remaining servers. Collection time is the source timestamp when available,
then a timestamp derived from source-cache age, otherwise collection time; it is
normalized to UTC.

| Tier | Table | Time key | Content used by charts |
| --- | --- | --- | --- |
| Raw | `server_metrics_raw` | `collected_at` | Individual snapshot values. |
| Hourly | `server_metrics_hourly` | `hour_timestamp` | Per completed-hour average for each supported key. |
| Daily | `server_metrics_daily` | `date` | Per completed-day average for each supported key. |

Raw snapshots also retain RAM/disk totals, PHP process count, PHP and OS
versions, uptime, source collector identity, and ingestion time. Hourly and
daily rows retain sample counts and additional min/max fields. The chart API
selects the average field for aggregated tiers; it does not expose those
min/max fields or sample counts.

All storage, aggregation, and chart-range boundaries are UTC. Aggregation uses
half-open windows: `>= start` and `< end`. A sample at an exact hour or midnight
belongs to the new window, never the preceding one.

## Aggregation

`cron/servers_metrics_aggregate.php` runs every 15 minutes. It plans UTC windows
without relying on the latest stored aggregate, then upserts each planned row.
Reruns therefore repair aggregates when late samples arrive or previous cron
runs were missed; they do not create duplicate hourly or daily rows.

Defaults from `config/example/data_retention.json.example`:

| Tier | Lag | Catch-up | Planning behavior |
| --- | --- | --- | --- |
| Hourly | 5 minutes | 24 hours | After subtracting lag, aggregate the preceding completed hour and the preceding bounded hours. |
| Daily | 30 minutes | 7 days | After subtracting lag, aggregate the preceding completed UTC day and the preceding bounded days. |

Hourly aggregates always read raw samples. Daily aggregation first reads raw
samples for the day. If none remain, it falls back to hourly aggregates and
uses sample-count-weighted averages; it does not average hourly averages
unweighted. Missing values remain null in SQL averages, and aggregation never
invents zero samples.

A current hour or current UTC day has no aggregate row. It becomes eligible only
after its configured lag. Delayed scheduler runs and configuration changes can
move the practical availability later.

## Range and tier selection

The general series API accepts a positive duration in `N[hdwmy]`, `all`, or
`max`, plus `raw`, `hourly`, `daily`, or `auto` granularity. With `auto`:

| Requested range form | Selected tier |
| --- | --- |
| `Nh`, where `N <= 6` | raw |
| `Nh`, where `N > 6` | hourly |
| `Nd`, where `N <= 7` | hourly |
| `Nd`, where `N > 7` | daily |
| `Nw`, where `N <= 1` | hourly |
| `Nw`, where `N > 1` | daily |
| `Nm` or `Ny`; `all` or `max` | daily |

An explicit tier is honored even when it is not the automatic choice. The shared
server-card modal has a narrower fixed set:

| Modal range | Tier |
| --- | --- |
| `6h` | raw |
| `24h`, `7d` | hourly |
| `30d` | daily |

## API and modal

`GET /api/metrics/{server}/{metric}` returns JSON with `filters`, a chart-ready
`series`, and cache metadata. `range` defaults to `24h`; `granularity` defaults
to `auto`; `force=1`, `true`, or `yes` bypasses the series cache. Labels are UTC
strings: seconds for raw, minutes for hourly, and dates for daily.

```json
{
  "filters": {"server": "node-a", "metric": "cpu", "range": "24h", "granularity": "hourly"},
  "series": {
    "labels": ["2026-07-12 10:00"],
    "data": [42.5],
    "min": 42.5,
    "max": 42.5,
    "avg": 42.5,
    "unit": "%"
  },
  "cache": {"from_cache": false, "stale": false, "ttl": 0, "age": 0}
}
```

No rows is a successful `200` response with empty `labels` and `data` and null
statistics. Rows whose requested value is unavailable remain JSON `null`. The
shared modal treats nulls as gaps, reports partial data when some values are
missing, and shows an empty state if no numeric value exists. Invalid filters
return `422` from the JSON API; invalid modal metric/range input returns a `422`
HTML validation fragment.

`GET /watch/modal/{server}/metrics?metric=cpu&range=24h` renders the shared HTML
modal. It permits `cpu`, `ram_percent`, `disk_percent`, and `file_count` only;
the default is CPU for 24 hours. Its client requests the canonical single-series
API as controls change or Refresh is clicked. The existing
`/watch/modal/{server}/process-count` modal is separate and always requests
daily process-count history.

`/api/metrics/{server}/compare` returns multiple datasets for one server.
`/api/metrics/dashboard` returns the configured multi-server dashboard chart.
Their endpoint contract and configured inputs are in the
[Dashboard Internal Endpoint Reference](../api/dashboard-endpoints.md).

## Retention ownership

Aggregation never deletes data. `cron/retention_cleanup.php`, scheduled at
`02:25` daily by `docker/cron/crontab`, owns metric retention for all three
server tiers. The example configuration defaults are:

| Setting | Default | Table |
| --- | --- | --- |
| `metrics.raw_retention_days` | 30 days | `server_metrics_raw` |
| `metrics.hourly_retention_days` | 90 days | `server_metrics_hourly` |
| `metrics.daily_retention_days` | 365 days | `server_metrics_daily` |
| `pruning.batch_limit` | 5000 rows | Bounded delete batch size |

Set a retention value to `0` to disable cleanup for that tier. Cleanup deletes
rows older than the tier cutoff in bounded batches. The relevant configuration
is `config/data_retention.json`; use
`config/example/data_retention.json.example` as the current shape.

## Diagnostics and limitations

```bash
# Counts and latest timestamp in each server-metric tier
docker exec <web-container> php /var/www/html/scripts/server_metrics_diagnostic.php

# Collection execution log and structured ingestion-health report
tail -f logs/cron/app-servers-metrics-collect.log
tail -f logs/cron/collect_server_metrics_health.log
```

The collector writes one JSON health report after each run with before/after raw
counts, newest sample time, and lag per processed server. It emits an ingestion
summary event and a `server_metrics.ingestion_stall` warning when a server adds
no row and its latest sample reaches the configured threshold (15 minutes by
default). The health report has application-managed rotation configured under
`ingestion_health`.

Known limits:

- Charts are sparse: absent collection or aggregate windows produce no label;
  null values produce label-aligned gaps. Neither is filled with zero.
- Historical availability is bounded by independent raw, hourly, and daily
  retention. Requesting a tier after it has been pruned returns no rows.
- Daily fallback depends on retained hourly rows. If both raw and hourly data
  are gone, an old daily aggregate cannot be rebuilt.
- The current server-card view is not a live historical sample stream and
  unsupported node fields do not become historical charts automatically.
