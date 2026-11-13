# Add your first server

This guide connects a MATA Node to the dashboard. Install the node on the
machine being monitored and run the dashboard somewhere that can reach it.

## Prerequisites

- A running MATA Dashboard. Start with the [quickstart](quickstart.md).
- PHP 8.3 or newer on the node.
- A web server serving the node's `public/` directory.
- HTTPS between the dashboard and the node in production.

MATA uses pull-only traffic. The dashboard calls the node; the node never calls
back and never sends alarms.

## 1. Add the server to the dashboard

`server add` writes both server files, keeps the key out of `servers.json`, and
generates the node's key:

```bash
make mata ARGS="server add prod-web-01 --api-url https://node.example.com/ --title 'Production web'"
```

It prints the `DASHBOARD_SERVER_ID` and `DASHBOARD_API_KEY` lines for the
node's `config/config.env`, then tests the node with the same signed request the
dashboard uses. Until the node has the key, the test fails and the command
exits `1`. If the node already has a key, pass it with `--api-key` (or set
`MATA_NODE_API_KEY`).

!!! info "Who runs it"
    `server add` and `server remove` write `config/`, so run them as its owner
    (your SSH account), not as the web server user; `make mata` does that. In
    Docker, production mounts `config/` read-only into the web container, so
    `make mata` runs these two in a short-lived container with `config/`
    writable instead. See [which account runs `bin/mata`](configuration.md#validate-changes).

### Editing the server files by hand

Setup creates `config/servers.json` and `config/servers.secrets.json` from
ignored `_EXAMPLE` entries. Replace the contents of `servers.json` with the real
node identity:

```json
{
  "prod-web-01": {
    "title": "Production web",
    "api_url": "https://node.example.com/"
  }
}
```

Generate a shared secret with `openssl rand -hex 32` and put it in
`servers.secrets.json` under the same name, and as `DASHBOARD_API_KEY` on the
node. Keeping it out of `servers.json` lets you
[version your config](version-config.md) without committing credentials:

```json
{
  "prod-web-01": {
    "api_key": "<generated-secret>"
  }
}
```

`api_url` is the node base URL. See the
[MATA Node API documentation](https://github.com/mata-sh/mata-node#api) for its
routes. The [security architecture](../architecture/security.md) documents node
request authentication.

## 2. Set up the node

Follow the [MATA Node README](https://github.com/mata-sh/mata-node) and the
[node configuration reference](../node/configuration.md). For a native node,
the short version is:

```bash
git clone https://github.com/mata-sh/mata-node.git
cd mata-node
make setup
```

Paste the printed key when node setup asks for `DASHBOARD_API_KEY`, and set
`DASHBOARD_SERVER_ID` to the server name from step 1. Configure the
applications to watch in `config/apps.json`; at minimum, an app needs `title`,
`url`, `path`, and `env`.

Expose the node's `public/` directory through your web server, then check it
from the dashboard:

```bash
curl https://node.example.com/health          # HTTP 200, status "healthy"
make mata ARGS="server test prod-web-01"      # exit 0: health and a signed request pass
```

A 503 from `/health` means the node has a configuration problem, such as a
missing key.

## 3. Schedule collection

Docker setup installs the dashboard cron manifest automatically. For native or
shared-hosting deployments, install
[`shared-hosting crontab template`](https://github.com/mata-sh/mata-dashboard/blob/main/cron/crontab.shared-hosting.template)
in the PHP user crontab. Set `APP_ROOT` and `PHP_BIN` first. The template runs
collection, metric aggregation, the library audit, alarm evaluation and
dispatch, and cleanup. The
[server metrics pipeline](../architecture/server-metrics-pipeline.md) documents
the metrics jobs.

## 4. Check the result

Inspect the dashboard after the next collection cycle. In Docker, inspect the
collector files from the web container or the bind-mounted `logs/` directory:

```bash
make shell
# inside the container
tail -n 50 logs/cron/app-servers-data-fetch.log
```

Collection writes to these logs:

```text
logs/cron/app-servers-data-fetch.log
logs/cron/app-servers-metrics-collect.log
logs/health/collect_server_metrics_health.log
```

The first job fetches application data and records events. The second writes
historical server snapshots. A node can appear in the dashboard while its
historical charts are still empty until a metrics collection run succeeds.

## Troubleshooting

### The node is not reachable

- Run `curl https://node.example.com/health` from the dashboard host.
- Check DNS, firewall rules, and the node web-server document root.
- Use an HTTPS URL. Use `allow_http` only for local development.

### Authentication fails

- Run `bin/mata server test <name>`: it names the likely cause for 401, 403,
  429, and 503 responses.
- Compare the JSON property name with `DASHBOARD_SERVER_ID` exactly.
- Compare `api_key` in `servers.secrets.json` with `DASHBOARD_API_KEY` exactly.
- Check that the node and dashboard system clocks are synchronized.
- Generate a new secret and replace it on both sides if in doubt.
- Check the node and dashboard logs for the rejected request reason.

### The server is visible but has no history

- Check `logs/cron/app-servers-metrics-collect.log`.
- Verify `disabled` is not `true`, that `api_url` is present, and that `servers.secrets.json` has the server's `api_key`.
- Run `bin/mata metrics status`; a stale `raw` tier means collection stopped.
- Confirm that the database is reachable and migrations have run.

### Application fields are missing

The node omits fields for disabled monitoring work. An absent `status`, `logs`,
or `session` field means "not measured", not zero. Check the app's
`monitoring` settings in the node's `config/apps.json`.

## Next steps

- [Configuration](configuration.md)
- [Application error monitoring](application-error-monitoring.md)
- [Alarm decision and delivery flow](../reference/alarms.md)
- [Secure an installation](secure-application.md)
