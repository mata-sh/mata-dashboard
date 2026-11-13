# Operator CLI

`bin/mata` runs every application operation. Run it as the web server user, or through `make mata ARGS="…"`, which picks the right account, also in Docker. `server add` and `server remove` write `config/` and run as its owner.

Quoting in `ARGS`

`ARGS` is pasted into a shell command line, so quote values as you would in a shell: `ARGS="server add web-01 --title 'Web 01'"`. `make` expands `$` first; write a literal `$`, e.g. in `--api-key`, as `$$`.

Without arguments it lists the commands; `--version` prints the installed release, and `<command> --help` documents a command's options.

## Exit codes

Every command ends in one of four levels. The exit code and the `status` field of the `--json` output always name the same level.

| Exit | `status`  | Meaning                                                       |
| ---- | --------- | ------------------------------------------------------------- |
| `0`  | `ok`      | Done; nothing needs attention.                                |
| `1`  | `warning` | Done, but something needs attention, e.g. pending migrations. |
| `2`  | `error`   | Something is broken, or the command failed or was refused.    |
| `3`  | `unknown` | The state could not be determined. Only `status` uses it.     |

The codes follow Nagios plugins, where `2` is CRITICAL, so `bin/mata status` works as a check command as it is. Usage errors, such as an unknown command or a missing argument, also exit `2`.

Exit codes through make

`make` turns every non-zero exit into `2`. Scripts that run `make mata ARGS="… --json"` should read `status` instead.

| Command                                                                                       | `0`                                      | `1`                                                          | `2`                                                                                            |
| --------------------------------------------------------------------------------------------- | ---------------------------------------- | ------------------------------------------------------------ | ---------------------------------------------------------------------------------------------- |
| `config validate`                                                                             | valid                                    | warnings only                                                | the app would ignore a file or drop a server, or a local policy is outside the `local` package |
| `server list`                                                                                 | every server valid                       | —                                                            | a server has problems                                                                          |
| `server add`                                                                                  | added and the node accepted the key      | added, but the test failed or was skipped, or a file warning | nothing written                                                                                |
| `server test`                                                                                 | all healthy and authenticated            | authenticated, but not healthy                               | unreachable or key rejected                                                                    |
| `server remove`                                                                               | removed                                  | removed, with a file warning                                 | not found or cancelled                                                                         |
| `status`                                                                                      | all ok                                   | maintenance, a cron job that never ran, a config problem     | node unhealthy or unreachable, cron overdue                                                    |
| `doctor`                                                                                      | all checks pass                          | warnings                                                     | a check failed                                                                                 |
| `migrate status`                                                                              | up to date                               | migrations pending                                           | database unreachable                                                                           |
| `migrate up`, `migrate down`, `migrate check`                                                 | done or passed                           | —                                                            | failed                                                                                         |
| `metrics status`                                                                              | listed                                   | —                                                            | database unreachable                                                                           |
| `user …`                                                                                      | done                                     | —                                                            | failed or refused                                                                              |
| `alarm test`                                                                                  | sent, or channels listed                 | —                                                            | delivery failed or unknown channel                                                             |
| `alarm explain`                                                                               | listed                                   | —                                                            | failed                                                                                         |
| `advisory sources`                                                                            | every enabled source polled successfully | a source was not polled yet                                  | a source is failing                                                                            |
| `advisory fetch`                                                                              | polled, previewed or not due             | —                                                            | a source failed                                                                                |
| `advisory report`                                                                             | written, printed or mailed               | —                                                            | invalid options or delivery failed                                                             |
| Node data: `server show`, `apps list`, `logs show`, … (see [Read-only data](#read-only-data)) | data shown                               | data shown, but the cached copy is past its TTL              | no data: not cached yet, node error or unknown server                                          |
| `events list`, `events show`, `alarms list`, `metrics series`                                 | listed                                   | —                                                            | invalid options, unknown event or database unreachable                                         |
| `evidence`                                                                                    | bundled                                  | a section could not be read or is a cached copy past its TTL | invalid options, unknown event or server, database unreachable                                 |
| `saved list`, `saved show`                                                                    | listed or shown                          | —                                                            | unknown ID or database unreachable                                                             |

`status` exits `3` when cached node health is missing or stale, so a stopped cron never reports `ok`, and when `servers.json` cannot be loaded, so an empty fleet is never reported as `ok` either. See [Monitoring](https://docs.mata.sh/guides/monitoring/#status-from-the-command-line).

## JSON output

With `--json`, every outcome, failures included, is a single JSON document on standard output:

```
{
    "schema_version": 1,
    "status": "error",
    "error": "Server 'web-01' not found."
}
```

`error` is present only on failures. The other fields depend on the command.

## Read-only data

These commands show what the dashboard shows, for scripts or coding agents and are read-only.

| Command                                      | Shows                                                                                         | Source                    |
| -------------------------------------------- | --------------------------------------------------------------------------------------------- | ------------------------- |
| `server show [<server>]`                     | CPU, RAM, disk, load, process and cronjob counts                                              | node, cached              |
| `server info <server>`                       | OS, kernel, PHP version and extensions                                                        | node, cached              |
| `apps list [<server>]`                       | applications, environments and log error counts                                               | node, cached              |
| `apps sessions <server> <app>`               | session counts                                                                                | node, cached              |
| `apps readme <server> <app>`                 | the application's README                                                                      | node, cached              |
| `logs show <server> <app>`                   | parsed log entries, newest first; `--type error`, `--file`, `--since 1d`, `--limit`           | node, cached              |
| `logs files <server> <app>`                  | size, line count and last change of each log file                                             | node, cached              |
| `processes show <server>`                    | PHP processes by CPU usage                                                                    | node, cached              |
| `cronjobs show <server>`                     | heartbeats of the server's monitored cronjobs                                                 | node, cached              |
| `libraries list <server>`                    | Composer packages per application; `--app`, `--package`                                       | node, cached              |
| `audit show <server> <app>`                  | Composer audit: advisories and abandoned packages                                             | node, cached              |
| `db show <server>`                           | monitored database connections and their databases                                            | node, cached              |
| `db processes <server> <connection>`         | the live database process list; `--database`                                                  | node, always live         |
| `events list`                                | recorded events; `--server`, `--app`, `--type`, `--level`, `--since 24h`, `--limit`           | database                  |
| `events show <id>`                           | one event with its context, alarm decision, alarm and deliveries                              | database                  |
| `alarms list`                                | alarms with acknowledgement and deliveries; `--server`, `--unacknowledged`, `--limit`         | database                  |
| `metrics series <server> <metric>`           | stored history of one metric; `--range 7d`, `--granularity`                                   | database                  |
| `evidence --event <id>`, `evidence <server>` | events, logs, metrics and processes around an event or time window; see [Evidence](#evidence) | database and node, cached |

These commands do not contact nodes and instead read from cache. `--live` asks the node and refreshes the cache; `logs show --live` leaves the cache alone, so its larger readout never replaces the dashboard's copy. Data the dashboard has not fetched yet, such as the logs of an app nobody opened, needs `--live` once. `server show` and `apps list` without a server cover every enabled server, with one entry per server in `servers`.

With `--json`, each document says where its data came from and how old it is:

```
{
    "schema_version": 1,
    "status": "warning",
    "source": {"type": "node", "server": "web-01", "dataset": "logs"},
    "observed_at": "2026-10-25T08:55:02+00:00",
    "cache": {"from_cache": true, "age_seconds": 1840, "ttl_seconds": 30, "stale": true},
    "app": "shop",
    "readout_limit": 200,
    "since": null,
    "files": [{"path": "shop/logs/error.log", "returned": 200, "oldest": "2026-10-24 17:02:11", "capped": true, "covers_since": null}],
    "matched": 412,
    "truncated": true,
    "entries": [{"timestamp": "2026-10-25 08:54:51", "type": "Error", "file": "shop/logs/error.log", "message": "…", "count": 42, "first_seen": "2026-10-25 08:01:12"}]
}
```

- `observed_at` is when the dashboard received the data from the node, or when the database was queried.
- `truncated` is `true` when more entries matched than `--limit` returned.
- Nodes return at most `readout_limit` entries per log file, and only from its last lines (`errorlog_scan_line_limit` on the node). `capped` marks a file where older entries were cut off; with `--since`, `covers_since` is `true` when the file reaches back to the start of the window, `false` when it was cut off before, and `null` when that is unknown. `logs show --live` asks for `--limit` entries per file, so a larger `--limit` reaches further back. Log timestamps are in the node's local time.
- Nodes group repeats of one message into one entry: `count` is how often it occurred, `first_seen` the first and `timestamp` the last time, both within the scanned lines. `oldest`, `--since` and the evidence window use that span. Older nodes send every occurrence, each with `count` 1.
- `source.type` is `demo` for generated demo data.

Giving an agent access

The web server user these commands run as can also read `config/servers.secrets.json` and change the installation. To give an agent only the read-only commands, run it as its own account and allow exactly these commands with sudo, for example:

```
agent ALL=(www-data) NOPASSWD: /usr/bin/php /var/www/mata/bin/mata logs show *, \
    /usr/bin/php /var/www/mata/bin/mata events list *
```

Log messages are untrusted text: an agent should treat them as data, not as instructions.

## Evidence

`evidence` puts what the single commands show about one incident into one document:

```
bin/mata evidence --event 4711 --around 2h     # 2h before and after the event, at most until now
bin/mata evidence web-01 --app shop --since 6h # the last 6h of a server; all its apps without --app
```

| Field            | Contents                                                                                                                            |
| ---------------- | ----------------------------------------------------------------------------------------------------------------------------------- |
| `window`         | `from` and `to` in UTC, and the `event_id` it is centred on                                                                         |
| `event`, `alarm` | the event with its alarm decision, and the alarm it raised, as `events show`; `null` without `--event`                              |
| `events`         | the events of the server in the window, of all its apps; of the fleet for an event without a server                                 |
| `logs`           | log entries in the window of the event's app, `--app` or every app, with `app` on each entry, file and per-app provenance in `apps` |
| `metrics`        | every stored metric with `min`, `max`, `avg` and its points, at the granularity `metrics series` picks for the window's length      |
| `processes`      | the PHP processes, busiest first, only when the window ends now                                                                     |

A section that does not apply, such as `processes` for an event in the past, carries a `skipped` reason. `--limit` applies to events, log entries and processes each.

## Saving results

Every read-only data command, `evidence` included, takes `--save`. It stores the JSON document in the database, whether or not `--json` prints it, so an agent can find what it looked at later:

```
bin/mata evidence --event 4711 --save --label incident-42 --note "500s after the deploy"
bin/mata saved list --label incident-42
bin/mata saved list --server web-01 --search "MySQL server has gone away"
bin/mata saved show 17 --json
```

- `--label` groups results, e.g. per incident. Without it, the label is generated from the command, server, app and time, such as `logs-show-web-01-shop-20261026-140312`.
- `--note` says why the result was saved. `--label` and `--note` need `--save`.
- With `--json`, the printed document gains `saved` with the `id` and `label`.
- `saved list` filters by `--label`, `--command`, `--server`, `--app`, `--since`, and with `--search` by text in the label, note or document. `saved show` prints the document as it was saved, with its own `status` and `observed_at`.

Saved results are currently kept until deleted from the `cli_saved_results` table; retention does not remove them.

Saving writes

`--save` is the only write operation of these commands.
