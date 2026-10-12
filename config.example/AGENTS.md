# MATA Dashboard configuration: agent notes

## Running the CLI

Run operator commands from the dashboard checkout as `make mata ARGS="<command>"` (from elsewhere:
`make -C /path/to/mata-dashboard mata ARGS="…"`). It picks the right account and, for Docker
installs, the container: the config owner for `server add`/`remove`, the web server user for
everything else. Never run them as root. Plain `php bin/mata <command>` works only for native
installs, and only as the right user.

## Rules

- **Validate after every edit:** `make mata ARGS="config validate"`. Exit 2 means the app would
  ignore a file or drop a server; do not commit that. To validate a checkout that is not the
  instance's `config/`, run natively: `php bin/mata config validate --path <dir>` (in Docker the
  path would be resolved inside the container).
- **Never commit** `servers.secrets.json`, `*.env`, or `users.json`; they hold credentials.
- **Add and remove servers with the CLI**, which writes both files with the right permissions and
  tests the node: `make mata ARGS="server add <name> --api-url https://node.example.com/"`. The
  name must equal the node's `DASHBOARD_SERVER_ID`.
- **Keep permissions when editing by hand:** the web server reads these files through their group.
  `servers.secrets.json` must stay `0640` with the web server's group, or every server fails to load.

## Checking the effect

- `make mata ARGS="server test [name]"`: node reachable and key accepted.
- `make mata ARGS="status --json"`: node health and cron, from the last collection run.
- `make mata ARGS="alarm explain [server]"`: why recent events did or did not alarm, after the next
  evaluation run.
- `make mata ARGS="doctor"`: database, migrations, OPA, users file, cron.

Every command takes `--json` and documents its exit codes in `--help`
(`make mata ARGS="<command> --help"`).