# Application error monitoring

`cron/servers_data_fetch.php` records fatal-error and log-size events from its forced `GET /apps` response. Dashboard rendering displays those summaries.

Deploy the dashboard before the node. The dashboard accepts old node payloads during the rollout; legacy app flags are ignored and omitted `monitoring` means every check is enabled.

## Node application settings

Configure optional per-app switches in mata-node `config/apps.json`. Every setting defaults to `true`; the node emits only `false` settings in its `/apps` response.

```json
{
  "shop": {
    "monitoring": {
      "loganalysis": false,
      "logsize": false,
      "sessions": false,
      "reachability": false,
      "alarms": false,
      "events": false
    }
  }
}
```

- `loganalysis`, `logsize`, and `sessions` skip their respective node scans and omit `status`, `logs`, and `session` from `/apps`.
- `reachability` excludes the app from availability probes.
- `events` suppresses all app events.
- `alarms` records events but prevents their alarms.

An omitted result means not measured, not zero. The dashboard shows disabled checks as unavailable and removes log modal triggers. The node rejects the matching detail route with HTTP 409 when `loganalysis`, `logsize`, or `sessions` is disabled.

The dashboard server configuration flags `error_monitoring_enabled` and `logsize_monitoring_enabled` remain server-scoped. They combine with the app's `loganalysis` and `logsize` settings respectively.

Fatal events are occurrences. A positive fatal count has an opaque cursor; an event is recorded only when that cursor changes. A valid zero count clears stored cursor state and does not resolve, delete, or acknowledge historical alarms. Fatal events have no event cooldown by default; alarm delivery remains throttled for one day.

Log-size monitoring evaluates the largest `/apps.logs` entry against `log_filesize_kb`. It keeps the existing event and alarm cooldown behavior.
