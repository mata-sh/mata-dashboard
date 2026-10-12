# Monitoring

MATA Dashboard polls configured MATA Nodes and records the results. Scheduled
jobs keep current application data, historical server metrics, events, alarms,
and deliveries up to date.

## Configure a node

Add a node to `config/servers.json` and its key to `config/servers.secrets.json`.
The `api_url` and `api_key` must match the node's public URL and `DASHBOARD_API_KEY`. See [Add your first server](first-server.md).

## Collection jobs

- `servers_data_fetch.php` fetches application data and records application,
  reachability, log-size, and cronjob events.
- `servers_metrics_collect.php` stores raw CPU, RAM, disk, file, and process
  snapshots.
- `servers_metrics_aggregate.php` stores hourly and daily summaries.
- The alarm evaluation jobs apply thresholds and the configured OPA or built-in
  decision driver.
- `alarms_dispatch.php` sends due email delivery records.

See the [server metrics pipeline](../architecture/server-metrics-pipeline.md) for
schedules, tables, retention, and diagnostics.

## Application monitoring

Application error, log-size, session, and reachability behavior is controlled
by the node's per-app `monitoring` settings. The dashboard also has two
server-scoped configuration flags. See [Application error monitoring](application-error-monitoring.md).

## Alarm delivery

Events, logical alarms, and email deliveries are separate records. A
notification rule can omit email without hiding a policy-evaluated logical
alarm; direct configuration-failure alarms bypass those rules. See the
[alarm decision and delivery flow](../reference/alarms.md).

## Status from the command line

`bin/mata status` reports node health and cron jobs for scripts, CI gates, and
Nagios-style checks:

```bash
php bin/mata status
php bin/mata status --json
make mata ARGS=status             # as the right account, also in Docker
```

It reads only what cron already wrote (the health cache from
`servers_data_fetch` and the cron heartbeats), so it never contacts a node and
can run as often as needed. Exit codes: `0` ok, `1` warning (maintenance, a cron
job that never ran), `2` critical (node unhealthy or unreachable, cron job
overdue), `3` unknown (health missing or older than twice its TTL, so a stopped
cron never reports ok).

!!! info "Scope"
    The status covers node health and cron. Metric thresholds, application
    probes, and alarms are only shown in the dashboard.

