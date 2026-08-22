# Docker deployment

The Compose stack runs the dashboard with Apache/PHP, MySQL, and OPA:

- `mata-web` serves the dashboard and runs the scheduled jobs.
- `mata-db` stores events, alarms, deliveries, and metrics.
- `mata-opa` evaluates alarm decisions from the read-only `policy/` mount.

MATA Node is a separate project. Run it on each monitored host and register it
in the dashboard's `config/servers.json`.

## Files

```text
.env.example                 Docker and Compose settings
config/*.env                 Application and database settings
Dockerfile                   Production image
Dockerfile.dev               Development image
docker-compose.yml           Shared services
docker-compose.dev.yml       Development override
docker-compose.prod.yml      Production override
docker/cron/crontab          Container crontab
docker/apache/               Apache configuration
docker/php/                  PHP configuration
docker/mysql/                MySQL development initialization
```

## Ports

| Service | Host binding | Variable | Notes |
| --- | --- | --- | --- |
| Dashboard | `0.0.0.0:3300` | `WEB_PORT` | Published by the base Compose file |
| OPA | `127.0.0.1:3320` | `OPA_PORT` | Development only |
| MySQL | `127.0.0.1:3306` | `MYSQL_PORT` | Development only |

The dashboard is the only service published by default in production. Put it
behind an HTTPS reverse proxy. The node has its own deployment and port.

## Configuration layers

Docker reads `.env` for Compose settings such as `APP_ENV`, `WEB_PORT`,
`MYSQL_ROOT_PASSWORD`, and `DATA_PATH`.

PHP reads `config/config.env`, `config/db.config.env`, and
`config/mail.config.env`. The dashboard container also receives the database
and mail settings through those files. `APP_ENV` in `.env` and
`config/config.env` must match.

Monitoring JSON files in `config/` are shared by the selected environment.
They are not selected by `APP_ENV`. The `demo` environment uses the fixed demo
event fixture in `config/demo/events.json`.

## Development

The guided setup is the recommended path:

```bash
make setup
```

Choose Docker mode and `dev` or `demo`. Setup starts the stack by default. If
you decline startup, run `make dev-start`. The target builds the development
image, starts MySQL and OPA, waits for a healthy database, and runs migrations.
The development override mounts the source tree and uses temporary MySQL
storage.

The dashboard is at `http://localhost:3300`, unless `WEB_PORT` was changed.
Create a user with:

```bash
make users-create MODE=docker ENV=dev
```

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

## Production

```bash
make setup MODE=docker ENV=prod START_SERVICES=no CREATE_USER=no
# Replace placeholder passwords in .env and config/db.config.env.
make prod-fix-perms MODE=docker ENV=prod
make prod-start MODE=docker ENV=prod
```

`prod-start` validates the environment
values, starts the production image, waits for healthy MySQL, and runs
migrations unless `RUN_MIGRATIONS=no` is set.

Production mounts:

- `config/` read-only;
- `logs/` writable for application and cron logs;
- `temp/` writable for cache and runtime state;
- a persistent MySQL data directory from `DATA_PATH`.

Docker stores users in the persistent `mata_users` named volume. The regular web
container does not edit that volume directly. User commands use a short-lived
admin container:

```bash
make users-list MODE=docker ENV=prod
make users-create MODE=docker ENV=prod
make users-passwd MODE=docker ENV=prod
```

## Cron jobs

The image installs `docker/cron/crontab` for `www-data`. The schedule is:

| Schedule | Job |
| --- | --- |
| `*/5 * * * *` | `servers_data_fetch.php`, then `servers_metrics_collect.php` |
| `4-59/15 * * * *` | `servers_metrics_aggregate.php` |
| `* * * * *` | `alarms_evaluate_critical.php` |
| `*/5 * * * *` | `alarms_evaluate_non_critical.php` |
| `* * * * *` | `alarms_dispatch.php` |
| `25 2 * * *` | `retention_cleanup.php` |
| `17 * * * *` | `rate_limit_cleanup.php` |

Inspect the installed crontab with:

```bash
docker compose \
  --env-file config/db.config.env --env-file .env \
  exec -u www-data mata-web crontab -l
```

Application cron logs are under `logs/cron/`. The metrics ingestion report is
`logs/health/collect_server_metrics_health.log`. Log rotation and retention
are configured in the cron manifest and `config/data_retention.json`.

## Common operations

```bash
ENV_NAME=dev # dev, demo, or prod
make logs MODE=docker ENV="$ENV_NAME"
make logs-web MODE=docker ENV="$ENV_NAME"
make logs-db MODE=docker ENV="$ENV_NAME"
make ps MODE=docker ENV="$ENV_NAME"
make shell MODE=docker ENV="$ENV_NAME"
make db-status MODE=docker ENV="$ENV_NAME"
make db-backup MODE=docker ENV="$ENV_NAME"
```

Use `make db-restore FILE=...` only after checking the target environment. The
Makefile requires confirmation for restores and production database writes.

Stop the selected stack with:

```bash
make down MODE=docker ENV=dev # replace dev with demo or prod
```

`make clean` removes the selected Compose-managed volumes. Production MySQL
uses `DATA_PATH/mysql`, which is a host bind mount and is not deleted by
`make clean`.

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

Then verify the node URL, shared credentials, database migrations, and the
server's active flag. Use
`php /var/www/html/scripts/server_metrics_diagnostic.php` inside `mata-web` for row counts.

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

Both overrides define a health check that requests `http://localhost/` inside the
container. Read its recorded output before the logs:

```bash
docker inspect --format='{{json .State.Health}}' mata-dashboard-web
docker compose --env-file config/db.config.env --env-file .env logs --tail=100 mata-web
```

Replace `mata-dashboard-web` if `COMPOSE_PROJECT_NAME` was changed. A restart loop
hides startup errors; start the service in the foreground to see them:

```bash
docker compose --env-file config/db.config.env --env-file .env up mata-web
```

### Bind-mount permissions fail

Run the production permission repair target once after provisioning or when a
host-side ownership problem is confirmed:

```bash
make prod-fix-perms MODE=docker ENV=prod
```

Do not make the whole repository writable by the web user. Only `logs/`,
`temp/`, and the configured authentication directory need application write
access.

## Security checklist

- Replace all database password placeholders before production startup.
- Publish only the dashboard or an HTTPS reverse proxy.
- Keep OPA and MySQL on the internal Docker network.
- Keep `config/` out of the web root and read-only in production.
- Back up the MySQL data and the `mata_users` volume.
- Use unique node API keys and rotate them if exposed.
