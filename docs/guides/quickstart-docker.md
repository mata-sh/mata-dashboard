# Docker quickstart

The Compose stack runs the dashboard, MySQL, and OPA. MATA Node is a separate
project installed on each monitored host.

## Requirements

Install Docker, the Docker Compose plugin, Git, and `make`.

## Development or demo

```bash
git clone https://github.com/mata-sh/mata-dashboard.git
cd mata-dashboard
make setup
```

Choose Docker mode and `dev` or `demo`. Setup creates the configuration and, by
default, builds and starts the stack, runs migrations, and offers to create a
user. If you decline startup, run:

```bash
make dev-start
```

The dashboard defaults to `http://localhost:3300`. `.env` controls `WEB_PORT`.
Development binds OPA and MySQL to `127.0.0.1` and uses temporary MySQL storage.

## Production

Generate the files without starting services:

```bash
make setup MODE=docker ENV=prod START_SERVICES=no CREATE_USER=no
```

Replace `MYSQL_ROOT_PASSWORD` in `.env` and `DB_PASSWORD` in
`config/db.config.env`, then start:

```bash
make prod-fix-perms MODE=docker ENV=prod
make prod-start MODE=docker ENV=prod
```

`prod-start` rejects placeholder database passwords, waits for MySQL, and runs
migrations unless `RUN_MIGRATIONS=no` is set. Put the published dashboard port
behind an HTTPS reverse proxy. OPA and MySQL remain internal.

!!! warning "Dashboard bind address"
    The dashboard port binds to `127.0.0.1` by default, so only a proxy on the
    same host can reach it. Set `WEB_BIND=0.0.0.0` in `.env` only when the proxy
    runs on another host. Docker publishes ports through its own iptables rules,
    which bypass host firewalls such as ufw or firewalld, so restrict access at
    the network or cloud firewall instead.

## Users

```bash
make mata MODE=docker ENV=prod ARGS="user list"
make mata MODE=docker ENV=prod ARGS="user create"
make mata MODE=docker ENV=prod ARGS="user passwd <name>"
```

User data lives in the persistent `mata_users` volume, which the web container
owns. `make mata` runs `bin/mata` there as the web server user; see
[User management](user-management.md).

## Operations

Set the target explicitly; the environment selects the Compose stack:

```bash
ENV_NAME=dev # dev, demo, or prod
make logs MODE=docker ENV="$ENV_NAME"
make ps MODE=docker ENV="$ENV_NAME"
make mata MODE=docker ENV="$ENV_NAME" ARGS="db status"
make db-backup MODE=docker ENV="$ENV_NAME"
```

Cron logs are under `logs/cron/`. Metrics ingestion health is in
`logs/health/collect_server_metrics_health.log`.

## Add a node

Install MATA Node on the monitored host and follow
[Add your first server](first-server.md).

## Stop or reset

```bash
ENV_NAME=dev # dev, demo, or prod
make down MODE=docker ENV="$ENV_NAME"
make clean MODE=docker ENV="$ENV_NAME"
```

`make clean` removes Compose-managed volumes after confirmation. In production,
MySQL uses the bind-mounted `DATA_PATH/mysql` directory; `make clean` does not
delete that directory.
