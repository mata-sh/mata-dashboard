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

## 1. Configure the node

Follow the [MATA Node README](https://github.com/mata-sh/mata-node) and the
[node configuration reference](../node/configuration.md).
For a native node deployment, the short version is:

```bash
git clone https://github.com/mata-sh/mata-node.git
cd mata-node
make setup
```

Generate a shared secret first:

```bash
openssl rand -hex 32
```

Copy its output, then set these values in the node's `config/config.env`:

```dotenv
DASHBOARD_SERVER_ID=prod-web-01
DASHBOARD_API_KEY=<paste-openssl-output>
```

`DASHBOARD_SERVER_ID` is the property name you will use in the dashboard's
`config/servers.json` and `config/servers.secrets.json`. Use the generated secret wherever this guide shows
`<paste-openssl-output>`.

Configure the applications to watch in `config/apps.json`. At minimum, an app
needs `title`, `url`, `path`, and `env`. Add log, session, Composer, and
per-app monitoring settings as needed.

Expose the node's `public/` directory through your web server. The node's
unauthenticated health check is:

```bash
curl https://node.example.com/health
```

It should return HTTP 200 with `status: "healthy"`. A 503 response means the
node has not completed startup or has a configuration problem.

## 2. Add the node to the dashboard

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

Put the shared secret in `servers.secrets.json` under the same name. Keeping it
out of `servers.json` lets you [version your config](version-config.md) without
committing credentials:

```json
{
  "prod-web-01": {
    "api_key": "<paste-openssl-output>"
  }
}
```

`api_url` is the node base URL. See the
[MATA Node API documentation](https://github.com/mata-sh/mata-node#api) for its
routes. The [security architecture](../architecture/security.md) documents node
request authentication.

## 3. Schedule collection

Docker setup installs the dashboard cron manifest automatically. For native or
shared-hosting deployments, install
[`shared-hosting crontab template`](https://github.com/mata-sh/mata-dashboard/blob/main/cron/crontab.shared-hosting.template)
in the PHP user crontab. Set `APP_ROOT` and `PHP_BIN` first. The template runs
node data collection, historical metrics collection and aggregation, alarm
evaluation, alarm dispatch, retention cleanup, rate-limit cleanup, and log
maintenance.

The current metrics pipeline is documented in the
[server metrics pipeline](../architecture/server-metrics-pipeline.md).

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

- Compare the JSON property name with `DASHBOARD_SERVER_ID` exactly.
- Compare `api_key` in `servers.secrets.json` with `DASHBOARD_API_KEY` exactly.
- Check that the node and dashboard system clocks are synchronized.
- Generate a new secret and replace it on both sides if in doubt.
- Check the node and dashboard logs for the rejected request reason.

### The server is visible but has no history

- Check `logs/cron/app-servers-metrics-collect.log`.
- Verify `disabled` is not `true`, that `api_url` is present, and that `servers.secrets.json` has the server's `api_key`.
- Run `php /var/www/html/scripts/server_metrics_diagnostic.php` in the dashboard container.
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
