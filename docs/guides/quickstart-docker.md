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

Setup replaces the placeholder `MYSQL_ROOT_PASSWORD` in `.env` and
`DB_PASSWORD` in `config/db.config.env` with generated values and keeps values
you set. Review `DATA_PATH` and `WEB_BIND` in `.env`, then start:

```bash
make prod-fix-perms MODE=docker ENV=prod
make prod-start MODE=docker ENV=prod
```

To install and start in one step, without prompts:

```bash
make setup MODE=docker ENV=prod START_SERVICES=yes ADMIN_USER=admin
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

## Unattended setup

Without a terminal, `make setup` never prompts: it uses the defaults below
unless a variable sets the answer, so a script or coding agent can run it.
Some agents and CI runners run commands in a pseudo-terminal, which looks like
a person typing; `NONINTERACTIVE=1` (or `CI=true`) makes setup and every other
`make` target behave as without a terminal anyway.

```bash
NONINTERACTIVE=1 make setup MODE=docker ENV=prod START_SERVICES=yes ADMIN_USER=admin
```

| Variable | Default without a terminal | Effect |
| --- | --- | --- |
| `MODE` | `local` | `docker` or `local` |
| `ENV` | `dev` | `dev`, `demo`, or `prod` |
| `START_SERVICES` | `yes`, `no` for `prod` | Docker: build and start the stack |
| `RUN_MIGRATIONS` | `yes` | Docker: migrate after startup |
| `ADMIN_USER` | none | Create this admin; without a terminal the password is generated and printed once |
| `CREATE_USER` | `no` | `no` skips `ADMIN_USER`; `yes` without a terminal requires it |
| `WEBSERVER` | `none` | Native: `htaccess`, `apache`, `nginx`, `caddy`, or `none` |
| `INSTALL_OPA` | `yes` | Native: download `bin/opa` |
| `INSTALL_OPA_SERVICE` | `no` | Native: install the systemd unit (needs sudo) |
| `MATA_USERS_FILE`, `PHP_FPM_USER` | none, `www-data` | Native: users file path and its owner |
| `CREATE_USERS_DIR` | `yes` | Native: create the users file directory with `sudo -n` |

Docker cannot create a user when the stack is not started, so `ADMIN_USER`
is ignored with `START_SERVICES=no`.

Commands that ask for a typed confirmation fail without a terminal and name
the variable that confirms instead: `ALLOW_PRODUCTION=CONFIRM_PRODUCTION` for
production writes, `ALLOW_RESTORE` for `make db-restore`, and
`ALLOW_DELETE="DELETE <env>"` for `make clean`.

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
`logs/health/collect_server_metrics_health.log`. Ports, mounts, the cron
schedule, and troubleshooting are in the [Docker deployment](../reference/docker.md)
reference.

## Add a node

Install MATA Node on the monitored host and follow
[Add your first server](first-server.md).

## Stop or reset

```bash
ENV_NAME=dev # dev, demo, or prod
make down MODE=docker ENV="$ENV_NAME"
make clean MODE=docker ENV="$ENV_NAME"
```

`make clean` removes Compose-managed volumes after confirmation; pass
`ALLOW_DELETE="DELETE dev"` to tear down a throwaway install from a script. In production,
MySQL uses the bind-mounted `DATA_PATH/mysql` directory; `make clean` does not
delete that directory.

To remove an install completely, run `make uninstall` (same confirmation). It
also deletes the built image and the files the containers own, which your
account cannot delete: `DATA_PATH/mysql`, `logs/`, and `temp/`. `config/` and
the checkout stay; delete them yourself.
