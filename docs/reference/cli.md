# Operator CLI

`bin/mata` runs every application operation. Run it as the web server user, or
through `make mata ARGS="…"`, which picks the right account, also in Docker.
`server add` and `server remove` write `config/` and run as its owner.

## Exit codes

Every command ends in one of four levels. The exit code and the `status` field of
the `--json` output always name the same level.

| Exit | `status`  | Meaning |
|------|-----------|---------|
| `0`  | `ok`      | Done; nothing needs attention. |
| `1`  | `warning` | Done, but something needs attention, e.g. pending migrations. |
| `2`  | `error`   | Something is broken, or the command failed or was refused. |
| `3`  | `unknown` | The state could not be determined. Only `status` uses it. |

The codes follow Nagios plugins, where `2` is CRITICAL, so `bin/mata status`
works as a check command as it is. Usage errors, such as an unknown command or
a missing argument, also exit `2`.

!!! warning "Exit codes through make"
    `make` turns every non-zero exit into `2`. Scripts that run
    `make mata ARGS="… --json"` should read `status` instead.

| Command | `0` | `1` | `2` |
|---------|-----|-----|-----|
| `config validate` | valid | warnings only | the app would ignore a file or drop a server |
| `server list` | every server valid | — | a server has problems |
| `server add` | added and the node accepted the key | added, but the test failed or was skipped, or a file warning | nothing written |
| `server test` | all healthy and authenticated | authenticated, but not healthy | unreachable or key rejected |
| `server remove` | removed | removed, with a file warning | not found or cancelled |
| `status` | all ok | maintenance, a cron job not run yet, a config problem | node unhealthy or unreachable, cron overdue |
| `doctor` | all checks pass | warnings | a check failed |
| `db status` | up to date | migrations pending | database unreachable |
| `db migrate`, `db rollback`, `db check` | done or passed | — | failed |
| `user …` | done | — | failed or refused |
| `alarm test` | sent, or channels listed | — | delivery failed or unknown channel |
| `alarm explain` | listed | — | failed |

`status` exits `3` when cached node health is missing or stale, so a stopped
cron never reports `ok`, and when `servers.json` cannot be loaded, so an empty
fleet is never reported as `ok` either. See [Monitoring](../guides/monitoring.md#status-from-the-command-line).

## JSON output

With `--json`, every outcome, failures included, is a single JSON document on
standard output:

```json
{
    "schema_version": 1,
    "status": "error",
    "error": "Server 'web-01' not found."
}
```

`error` is present only on failures. The other fields depend on the command.
