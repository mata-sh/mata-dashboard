# Application error monitoring

`cron/servers_data_fetch.php` records application fatal-error and log-size events from its forced `GET /apps` response. Dashboard rendering only displays summaries.

Nodes and the dashboard must be deployed together. Every application summary requires literal JSON booleans `error_monitoring_enabled` and `logsize_monitoring_enabled`. A missing or invalid value, or an inconsistent `status.fatal_cursor`, rejects the complete `/apps` payload.

Configure the node application and dashboard server with literal booleans:

```json
// mata-node config/apps.json
{"shop":{"error_monitoring_enabled":true,"logsize_monitoring_enabled":true}}
```

```json
// mata-dashboard config/servers.json
{"api-1":{"error_monitoring_enabled":false,"logsize_monitoring_enabled":true}}
```

Both settings default to enabled when omitted from a dashboard server entry. `false` at either server or application level disables only its matching check: error `false` does not disable log-size monitoring, and log-size `false` does not disable fatal monitoring.

Fatal events are occurrences. A positive fatal count has an opaque cursor; an event is recorded only when that cursor changes. A valid zero count clears stored cursor state and does not resolve, delete, or acknowledge historical alarms. Fatal events have no event cooldown by default; alarm delivery remains throttled for one day.

Log-size monitoring evaluates the largest `/apps.logs` entry against `log_filesize_kb`. It keeps the existing event and alarm cooldown behavior.

`/apps` contains summaries only. Detailed `/{app}/logs` and `/{app}/logs-status` retrieval remains on-demand and is never performed by the cron monitor.
