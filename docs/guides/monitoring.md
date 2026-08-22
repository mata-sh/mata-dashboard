# Monitoring

MATA Dashboard polls configured MATA Nodes and records the results. Scheduled
jobs keep current application data, historical server metrics, events, alarms,
and deliveries up to date.

## Configure a node

Add a node to `config/servers.json`. The `api_url` and `api_key` must match the
node's public URL and `DASHBOARD_API_KEY`. See [Add your first server](first-server.md).

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
server-scoped application flags. See [Application error monitoring](application-error-monitoring.md).

## Alarm delivery

Events, logical alarms, and email deliveries are separate records. A
notification rule can omit email without hiding a policy-evaluated logical
alarm; direct configuration-failure alarms bypass those rules. See the
[alarm decision and delivery flow](../reference/alarms.md).
