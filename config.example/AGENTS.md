# MATA Dashboard configuration: agent notes

This directory configures one MATA Dashboard instance. Copy this file into the instance's
`config/` (or its config repository) so agents working there follow these rules.

## Rules

- **Validate after every edit:** `php bin/mata config validate` (from the dashboard checkout; set
  `MATA_CONFIG_PATH` or `--path` for a checkout elsewhere). Exit 2 means the app would ignore a file
  or drop a server; do not commit that.
- **Secrets never go in `servers.json`.** Node API keys live only in `servers.secrets.json`, keyed by
  the server name. A server with `api_key` in `servers.json` is rejected.
- **Never commit** `servers.secrets.json`, `*.env`, or `users.json`; they hold credentials.
- **Add and remove servers with the CLI**, which writes both files with the right permissions and
  tests the node: `php bin/mata server add <name> --api-url https://node.example.com/`. The name must
  equal the node's `DASHBOARD_SERVER_ID`.
- **Keep permissions:** the web server reads these files through their group. `servers.secrets.json`
  must stay `0640` with the web server's group, or every server fails to load.
- **Run as the config owner for writes, as the web server user for checks**, never as root.

## Checking the effect

- `php bin/mata server test [name]`: node reachable and key accepted.
- `php bin/mata status --json`: node health and cron, from the last collection run.
- `php bin/mata alarm explain [server]`: why recent events did or did not alarm, after the next
  evaluation run.
- `php bin/mata doctor`: database, migrations, OPA, users file, cron.

Every command takes `--json` and documents its exit codes in `--help`.
