# Docker deployment

The Compose stack runs the dashboard with Apache/PHP, MySQL, and OPA:

- `mata-web` serves the dashboard and runs the scheduled jobs.
- `mata-db` stores events, alarms, deliveries, and metrics.
- `mata-opa` evaluates alarm decisions from the read-only `policy/` mount.

MATA Node is a separate project. Run it on each monitored host and register it
in the dashboard's `config/servers.json`, with its key in `config/servers.secrets.json`.

!!! info "Setup and day-to-day commands"
    This page is a reference. To install, start, stop, or manage users, follow
    the [Docker quickstart](../guides/quickstart-docker.md).

## Files

```text
.env.example                     Docker and Compose settings
config/*.env                     Application and database settings
Dockerfile                       Production image
Dockerfile.dev                   Development image
docker-compose.yml               Shared services
docker-compose.dev.yml           Development override
docker-compose.prod.yml          Production override
docker-compose.config-write.yml  Writable config/ for make mata server add/remove
docker/cron/crontab              Container crontab
docker/apache/                   Apache configuration
docker/php/                      PHP configuration
docker/mysql/                    MySQL development initialization
```

## Ports

| Service | Host binding | Variable | Notes |
| --- | --- | --- | --- |
| Dashboard | `127.0.0.1:3300` | `WEB_BIND`, `WEB_PORT` | Published by the base Compose file |
| OPA | `127.0.0.1:3320` | `OPA_PORT` | Development only |
| MySQL | `127.0.0.1:3306` | `MYSQL_PORT` | Development only |

The dashboard is the only service published by default in production. Put it
behind an HTTPS reverse proxy. The node has its own deployment and port.

## Configuration layers

Docker reads `.env` for Compose settings such as `APP_ENV`, `WEB_BIND`, `WEB_PORT`,
`MYSQL_ROOT_PASSWORD`, and `DATA_PATH`.

PHP reads `config/config.env`, `config/db.config.env`, and
`config/mail.config.env`.

!!! warning "Matching `APP_ENV`"
    `APP_ENV` in `.env` and `config/config.env` must match.

!!! info "Compose interpolation"
    The Makefile passes `--env-file config/db.config.env --env-file .env`, so
    `config/db.config.env` also feeds `${VAR}` interpolation in the Compose
    files, and `.env` wins on conflicts. Bare `docker compose` without these
    flags resolves variables differently; pass them when calling Compose
    directly.

Monitoring JSON files in `config/` are shared by the selected environment.
They are not selected by `APP_ENV`. The `demo` environment uses the fixed demo
event fixture in `config/demo/events.json`.

## Development override

The development override mounts the source tree, disables OPcache, publishes
OPA and MySQL on `127.0.0.1`, and uses temporary MySQL storage.

Direct Compose use requires the external network and configuration files:

```bash
make setup MODE=docker ENV=dev START_SERVICES=no CREATE_USER=no

docker compose \
  --env-file config/db.config.env \
  --env-file .env \
  -f docker-compose.yml -f docker-compose.dev.yml up -d --build
```

Use the Makefile for migrations, users, and logs so the selected environment is
checked consistently.

## Production mounts

- `config/` read-only (it may be a symlink to a separate configuration repository);
- `logs/` writable for application and cron logs;
- `temp/` writable for cache and runtime state;
- a persistent MySQL data directory from `DATA_PATH`.

Docker stores users in the persistent `mata_users` named volume, owned by
`www-data`; the container sets it up on start. `make mata` runs `bin/mata` in
the web container as that user.

Back up the MySQL data (`make db-backup`) and the `mata_users` volume.

`make clean` removes the selected Compose-managed volumes. Production MySQL
uses `DATA_PATH/mysql`, which is a host bind mount and is not deleted by
`make clean`. `make uninstall` deletes it too, together with the image, `logs/`, and
`temp/`, through a container, since the files belong to container users.

## Cron jobs

The image installs `docker/cron/crontab` for `www-data`:

```text
--8<-- "docker/cron/crontab"
```

Inspect the installed crontab with:

```bash
docker compose \
  --env-file config/db.config.env --env-file .env \
  exec -u www-data mata-web crontab -l
```

Application cron logs are under `logs/cron/`. The metrics ingestion report is
`logs/health/collect_server_metrics_health.log`. The `find` lines at the end of
the crontab delete logs and cache files older than their retention age; database
retention is set in `config/data_retention.json`.

## Troubleshooting

### The web container does not start

```bash
ENV_NAME=dev # dev, demo, or prod
make status MODE=docker ENV="$ENV_NAME"
docker compose --env-file config/db.config.env --env-file .env logs mata-web
```

Check that `.env` and `config/config.env` contain the same `APP_ENV`, that the
configuration files exist, and that the Docker daemon is running.

### MySQL is unavailable

```bash
ENV_NAME=dev # dev, demo, or prod
make wait-db MODE=docker ENV="$ENV_NAME"
docker compose --env-file config/db.config.env --env-file .env logs mata-db
```

Check `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD` in
`config/db.config.env`. In Compose, the application host is `mata-db`, not
`localhost`.

### Metrics are missing

Inspect the collector and health logs:

```bash
ENV_NAME=dev # dev, demo, or prod
make logs-web MODE=docker ENV="$ENV_NAME"
tail -f logs/health/collect_server_metrics_health.log
```

Then verify the node URL, shared credentials, database migrations, and that
the server is not `disabled`. Use `make mata ARGS="metrics status"` for row
counts.

### Code changes are not visible

The development image disables OPcache (`docker/php/php-dev.ini`). The production
image enables it with `opcache.validate_timestamps = 0`, so edited files are never
re-read while the container runs. Stale code in development usually means the
development override was not applied:

```bash
make ps MODE=docker ENV=dev
docker compose --env-file config/db.config.env --env-file .env \
  exec mata-web php -i | grep opcache.enable
```

Rebuild with the development image, or reset the cache in place:

```bash
make dev-start
docker compose --env-file config/db.config.env --env-file .env \
  exec mata-web php -r "opcache_reset();"
```

### The web container restarts or reports unhealthy

The web container has a health check. Read its recorded output before the logs:

```bash
docker inspect --format='{{json .State.Health}}' mata-dashboard-web
docker compose --env-file config/db.config.env --env-file .env logs --tail=100 mata-web
```

Replace `mata-dashboard-web` if `COMPOSE_PROJECT_NAME` was changed. A restart loop
hides startup errors; start the service in the foreground to see them:

```bash
docker compose --env-file config/db.config.env --env-file .env up mata-web
```

### Every page returns 500

The users file lives in the `mata_users` volume, not the repository. An empty
volume makes every route return the users-file error page. Create a user with
`make mata MODE=docker ENV=<env> ARGS="user create"`.

### Bind-mount permissions fail

Run the production permission repair target once after provisioning or when a
host-side ownership problem is confirmed:

```bash
make prod-fix-perms MODE=docker ENV=prod
```

Do not make the whole repository writable by the web user. Only `logs/`,
`temp/`, and the configured authentication directory need application write
access.

See [Secure an installation](../guides/secure-application.md) for the
production baseline.
