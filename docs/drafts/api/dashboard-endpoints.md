# Dashboard Internal Endpoint Reference

Routes registered by `src/Http/Routing/RouterSetup.php`. `/watch/*` returns HTML
pages or HTMX fragments; `/api/*` returns JSON. The node-facing API is separate:
[Node API Reference](node-api.md).

## Access and request handling

| Area | Router behavior |
| --- | --- |
| `/health` | Public JSON health response: `status` and Unix `timestamp`. |
| `/login` | Public; optional IP allowlist applies when enabled. |
| `/watch/*` | Requires a dashboard session; unauthenticated requests redirect to login. |
| `/api/*` | Requires a dashboard session; unauthenticated requests receive an unauthorized response. |
| Unsafe methods | Global CSRF validation applies to `POST`, `PUT`, `PATCH`, and `DELETE`. |
| `/login`, `/logout`, `/watch/*`, `/api/*` | Rate limiting is applied when the rate-limiter service is configured. |

## Authentication and dashboard HTML

| Method | Path | Response |
| --- | --- | --- |
| `GET` | `/login` | Login page. |
| `POST` | `/login` | Login submission. |
| `POST` | `/logout` | Logout. |
| `GET` | `/watch` | Dashboard page. |
| `GET` | `/watch/servers` | Server overview fragment. |
| `GET` | `/watch/events` | Events overview fragment. |
| `GET` | `/watch/alarms` | Alarms overview fragment. |
| `GET` | `/watch/libraries` | Libraries overview fragment. |
| `GET` | `/watch/databases` | Databases overview fragment. |
| `GET` | `/watch/settings` | Settings overview fragment. |
| `GET` | `/watch/notification-bell` | Notification-bell fragment. |
| `POST` | `/watch/notification-bell/ack-all` | Updated notification-bell fragment. |
| `GET` | `/watch/metrics` | Metrics dashboard fragment. |
| `GET` | `/watch/audit/section` | Composer audit fragment. |
| `POST` | `/watch/audit/run` | Run the configured audit and return its fragment. |
| `GET` | `/watch/audit/run/{server}/{app}` | Run an audit for one application and return its fragment. |

### Refresh fragments

These routes request fresh data from their controller rather than using its normal
cached read path.

| Method | Path | Response |
| --- | --- | --- |
| `GET` | `/watch/refresh/servers` | Server overview fragment. |
| `GET` | `/watch/refresh/server/{server}` | One server-card fragment. |
| `GET` | `/watch/refresh/serverstats/{server}` | One server-stats fragment. |
| `GET` | `/watch/refresh/databases` | Databases overview fragment. |

## Modal HTML

| Method | Path | Response |
| --- | --- | --- |
| `GET` | `/watch/modal/{server}/{app}/readme` | Application README modal. |
| `GET` | `/watch/modal/{server}/{app}/readme/refresh` | README modal with a forced source refresh. |
| `GET` | `/watch/modal/{server}/{app}/logs` | Application logs modal. |
| `GET` | `/watch/modal/{server}/{app}/logs/refresh` | Logs modal with a forced source refresh. |
| `GET` | `/watch/modal/{server}/database/{database}/processes` | Database process-list modal. |
| `GET` | `/watch/modal/{server}/database/{database}/processes/refresh` | Process-list modal with a forced source refresh. |
| `GET` | `/watch/modal/{server}/info` | Server-information modal. |
| `GET` | `/watch/modal/{server}/info/refresh` | Server-information modal with a forced source refresh. |
| `GET` | `/watch/modal/{server}/cronjobs` | Server cronjobs modal. |
| `GET` | `/watch/modal/{server}/cronjobs/refresh` | Cronjobs modal with a forced source refresh. |
| `GET` | `/watch/modal/{server}/metrics` | Shared server-card history modal. See [Server metrics](#server-metrics). |

## General JSON API

| Method | Path | Response / inputs |
| --- | --- | --- |
| `GET` | `/api/events` | Latest events JSON. |
| `GET` | `/api/alarms` | Latest alarms JSON; `unacknowledged=1` limits results to unacknowledged alarms. |
| `POST` | `/api/alarms/{alarmId}/ack` | `{ "success": true, "acknowledged": … }`. |
| `GET` | `/api/libraries` | Libraries view data JSON. |
| `GET` | `/api/logs/{server}/{app}` | Flattened application-log entries JSON; forces a source refresh. |
| `GET` | `/api/logs-status/{server}/{app}` | Application-log metadata JSON; forces a source refresh. |
| `POST` | `/api/database-notice/ack` | Requires POST `action`; returns `{ "success": true }` or a `400` JSON error. |

## Server metrics

The historical-series routes return JSON. The `dashboard` and `compare` routes
are registered before `/{server}/{metric}` so they are not interpreted as metric
names. See [Server Metrics Reference](../reference/server-metrics.md) for
collection and retention.

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/api/metrics/dashboard` | Configured multi-server metrics-dashboard chart payload. |
| `GET` | `/api/metrics/{server}/compare` | One server, multiple metric datasets. |
| `GET` | `/api/metrics/{server}/{metric}` | One server, one chart-ready series. |

### `GET /api/metrics/{server}/{metric}`

Metric names are normalized to lowercase with hyphens converted to underscores.
Supported keys and units are:

| Key | Unit |
| --- | --- |
| `cpu`, `ram_percent`, `disk_percent` | `%` |
| `ram_used`, `disk_used` | KiB |
| `file_count`, `process_count` | count |

| Query parameter | Default | Accepted values |
| --- | --- | --- |
| `range` | `24h` | Positive `N[hdwmy]` duration, `all`, or `max`. |
| `granularity` | `auto` | `auto`, `raw`, `hourly`, or `daily`. |
| `force` | off | `1`, `true`, or `yes` bypasses the series cache. |

With `granularity=auto`, selection is unit-sensitive:

| Range form | Selected tier |
| --- | --- |
| `Nh`, where `N <= 6` | raw |
| `Nh`, where `N > 6` | hourly |
| `Nd`, where `N <= 7` | hourly |
| `Nd`, where `N > 7` | daily |
| `Nw`, where `N <= 1` | hourly |
| `Nw`, where `N > 1` | daily |
| `Nm` or `Ny`; `all` or `max` | daily |

An explicit granularity is not remapped.

```json
{
  "filters": {
    "server": "node-a",
    "metric": "cpu",
    "range": "24h",
    "granularity": "hourly"
  },
  "series": {
    "server_name": "node-a",
    "metric": "cpu",
    "range": "24h",
    "granularity": "hourly",
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

A valid query with no rows returns `200`, empty `labels` and `data`, and null
statistics. A row with no value for the requested metric is represented as
`null`; it is not converted to zero. Invalid server, metric, range, or
granularity filters return a validation response (`422`).

### `GET /api/metrics/{server}/compare`

`metrics` is a comma-separated list and defaults to `cpu,ram_percent`; every
key must be supported. `range` and `granularity` have the same defaults and
validation as the single-series route. The response has `filters`, `series`, and
`cache`; `series` contains shared `labels` and a `datasets` entry for each
requested metric.

### `GET /api/metrics/dashboard`

The metrics dashboard selects a configured `metric`, `timespan`, and optional
comma-separated `servers`; `force=1|true|yes` bypasses its dashboard cache.
Available metrics, timespans, their ranges, and granularities come from the
metrics dashboard configuration rather than a fixed public list.

### `GET /watch/modal/{server}/metrics`

Renders HTML for the shared modal opened by CPU, RAM, disk, and file-count
server-card controls. Query parameters are restricted to:

| Parameter | Default | Accepted values |
| --- | --- | --- |
| `metric` | `cpu` | `cpu`, `ram_percent`, `disk_percent`, `file_count`, `process_count` |
| `range` | `24h` | `6h`, `24h`, `7d`, `30d` |

The modal resolves its tier as `6h` → raw, `24h`/`7d` → hourly, and `30d` →
daily. Its JavaScript fetches the single-series JSON route when controls change
or Refresh is clicked, displays timestamps as UTC, preserves null gaps, and
shows an empty state when no numeric values exist. The process-count history is
the total process count, while the server-card badge shows PHP processes.
