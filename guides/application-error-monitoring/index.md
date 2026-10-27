# Application error monitoring

`cron/servers_data_fetch.php` records fatal-error and log-size events from each node's `/apps` response, which it fetches fresh rather than from the cache. The dashboard shows the same summaries.

## Node application settings

Configure optional per-app switches in mata-node `config/apps.json`. Every setting defaults to `true`; the node emits only `false` settings in its `/apps` response.

```
{
  "shop": {
    "monitoring": {
      "loganalysis": false,
      "logsize": false,
      "sessions": false,
      "reachability": false,
      "alarms": false,
      "events": false,
      "libraryaudit": false
    }
  }
}
```

- `loganalysis`, `logsize`, and `sessions` skip their respective node scans and omit `status`, `logs`, and `session` from `/apps`.
- `reachability` excludes the app from availability probes.
- `events` suppresses all app events.
- `alarms` records events but prevents their alarms.
- `libraryaudit` stops the scheduled Composer audit and its security events for the app. Manual audits from the Audit page still work.

An omitted result means not measured, not zero. The dashboard shows disabled checks as unavailable and removes log modal triggers. The node rejects the matching detail route with HTTP 409 when `loganalysis`, `logsize`, or `sessions` is disabled.

The dashboard server configuration flags `error_monitoring_enabled` and `logsize_monitoring_enabled` remain server-scoped. They combine with the app's `loganalysis` and `logsize` settings respectively.

Fatal events are occurrences. A positive fatal count has an opaque cursor; an event is recorded only when that cursor changes. A valid zero count clears stored cursor state and does not resolve, delete, or acknowledge historical alarms. Fatal events have no event cooldown by default; alarm delivery remains throttled for one day.

Log-size monitoring evaluates the largest `/apps.logs` entry against `log_filesize_kb`. It keeps the existing event and alarm cooldown behavior.

## Library security advisories

`cron/libraries_audit.php` runs every 6 hours for apps with `composer` enabled on the node. It reads each app's Composer audit through the shared API cache, so the node runs `composer audit` only when the cached audit is older than the `audit` TTL in `ttl.json` (default 24 hours). Audits started from the Audit page refresh the same cache.

The job records `library_outdated_security` when an audit reports an advisory that was not in the previously evaluated audit for that app. The event is `critical` when a new advisory is `critical` or `high`, otherwise `warning`. Seen advisory IDs are stored in `app_library_audit_state`:

- Unchanged advisories record nothing.
- A fixed advisory leaves the stored set without an event; if it returns later, it is reported again.
- A clean audit clears the stored set.
- A failed or missing audit leaves the stored set untouched.

Info

Older MATA Nodes reject `libraryaudit` in `apps.json`; update the node before you set it.
