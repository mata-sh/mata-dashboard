# Docker deployment

The Compose stack runs the dashboard with Apache/PHP, MySQL, and OPA:

- `mata-web` serves the dashboard and runs the scheduled jobs.
- `mata-db` stores events, alarms, deliveries, and metrics.
- `mata-opa` evaluates alarm decisions from the read-only `policy/` mount.

MATA Node is a separate project. Run it on each monitored host and register it in the dashboard's `config/servers.json`, with its key in `config/servers.secrets.json`.

Setup and day-to-day commands

This page is a reference. To install, start, stop, or manage users, follow the [Docker quickstart](https://docs.mata.sh/guides/quickstart-docker/index.md).

## Files

```
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

| Service   | Host binding     | Variable               | Notes                              |
| --------- | ---------------- | ---------------------- | ---------------------------------- |
| Dashboard | `127.0.0.1:3300` | `WEB_BIND`, `WEB_PORT` | Published by the base Compose file |
| OPA       | `127.0.0.1:3320` | `OPA_PORT`             | Development only                   |
| MySQL     | `127.0.0.1:3306` | `MYSQL_PORT`           | Development only                   |

The dashboard is the only service published by default in production. Put it behind an HTTPS reverse proxy. The node has its own deployment and port.

## Configuration layers

Docker reads `.env` for Compose settings such as `APP_ENV`, `WEB_BIND`, `WEB_PORT`, `MYSQL_ROOT_PASSWORD`, and `DATA_PATH`.

PHP reads `config/config.env`, `config/db.config.env`, and `config/mail.config.env`.

Matching `APP_ENV`

`APP_ENV` in `.env` and `config/config.env` must match.

Compose interpolation

The Makefile passes `--env-file config/db.config.env --env-file .env`, so `config/db.config.env` also feeds `${VAR}` interpolation in the Compose files, and `.env` wins on conflicts. Bare `docker compose` without these flags resolves variables differently; pass them when calling Compose directly.

Monitoring JSON files in `config/` are shared by the selected environment. They are not selected by `APP_ENV`. The `demo` environment uses the fixed demo event fixture in `config/demo/events.json`.

## Development override

The development override mounts the source tree, disables OPcache, publishes OPA and MySQL on `127.0.0.1`, and uses temporary MySQL storage.

Direct Compose use requires the external network and configuration files:

```
make setup MODE=docker ENV=dev START_SERVICES=no CREATE_USER=no

docker compose \
  --env-file config/db.config.env \
  --env-file .env \
  -f docker-compose.yml -f docker-compose.dev.yml up -d --build
```

Use the Makefile for migrations, users, and logs so the selected environment is checked consistently.

## Production mounts

- `config/` read-only (it may be a symlink to a separate configuration repository);
- `logs/` writable for application and cron logs;
- `temp/` writable for cache and runtime state;
- a persistent MySQL data directory from `DATA_PATH`.

Docker stores users in the persistent `mata_users` named volume, owned by `www-data`; the container sets it up on start. `make mata` runs `bin/mata` in the web container as that user.

Back up the MySQL data (`make db-backup`) and the `mata_users` volume.

`make clean` removes the selected Compose-managed volumes. Production MySQL uses `DATA_PATH/mysql`, which is a host bind mount and is not deleted by `make clean`. `make uninstall` deletes it too, together with the image, `logs/`, and `temp/`, through a container, since the files belong to container users.

## Cron jobs

The image installs `docker/cron/crontab` for `www-data`:

```
# MATA Dashboard cron manifest (installed as the www-data user crontab)
# User crontab format: five schedule fields followed directly by the command. No username.
SHELL=/bin/sh
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
APP_ROOT=/var/www/html
PHP_BIN=/usr/local/bin/php
LOG_DIR=/var/www/html/logs
CRON_LOG_DIR=/var/www/html/logs/cron
CRON_ENV_FILE=/etc/mata/cron.env

# Job output uses the application log. The redirect also captures shell, parse, and
# other failures before PHP bootstrap configures Monolog.
# Heartbeat history: $CRON_LOG_DIR/heartbeat-<job-name>.jsonl
# PHP runtime errors: $CRON_LOG_DIR/php-error.log
# Metrics ingestion health: $LOG_DIR/health/collect_server_metrics_health.log
# (logs/health/ is application-managed — size-based rotation — and excluded below).

# Data collection
# servers_data_fetch warms the dashboard api-cache (node health, server data, apps,
# availability probes), one server at a time. Chained on purpose: collect ingests
# from the freshly warmed entries (serverData TTL is 180s), so the nodes are
# queried once per cycle. ';' not '&&': a fetch failure must not stop metrics
# collection — collect then fetches on its own through the same shared cache.
*/5 * * * * . "$CRON_ENV_FILE" && cd "$APP_ROOT" && { "$PHP_BIN" cron/servers_data_fetch.php >> "$CRON_LOG_DIR/app-servers-data-fetch.log" 2>&1 ; "$PHP_BIN" cron/servers_metrics_collect.php >> "$CRON_LOG_DIR/app-servers-metrics-collect.log" 2>&1 ; }
# Offset from the :00/:15 slots so it does not start alongside the collection chain.
4-59/15 * * * * . "$CRON_ENV_FILE" && cd "$APP_ROOT" && "$PHP_BIN" cron/servers_metrics_aggregate.php >> "$CRON_LOG_DIR/app-servers-metrics-aggregate.log" 2>&1
# Composer audits through the api-cache: the node is only asked again once an app's
# audit is older than the audit TTL (default 24h), so 6-hourly runs keep it under 30h.
40 */6 * * * . "$CRON_ENV_FILE" && cd "$APP_ROOT" && "$PHP_BIN" cron/libraries_audit.php >> "$CRON_LOG_DIR/app-libraries-audit.log" 2>&1

# Security advisories (config/advisories.json; both exit at once without it)
# Each source is fetched once its poll_interval_minutes passed; runs in between only check.
# The digest job runs daily and mails once advisories.digest.every_days passed.
2-59/5 * * * * . "$CRON_ENV_FILE" && cd "$APP_ROOT" && "$PHP_BIN" cron/advisories_poll.php >> "$CRON_LOG_DIR/app-advisories-poll.log" 2>&1
15 7 * * * . "$CRON_ENV_FILE" && cd "$APP_ROOT" && "$PHP_BIN" cron/advisories_digest.php >> "$CRON_LOG_DIR/app-advisories-digest.log" 2>&1

# Alarm evaluation and delivery
* * * * * . "$CRON_ENV_FILE" && cd "$APP_ROOT" && "$PHP_BIN" cron/alarms_evaluate_critical.php >> "$CRON_LOG_DIR/app-alarms-evaluate-critical.log" 2>&1
*/5 * * * * . "$CRON_ENV_FILE" && cd "$APP_ROOT" && "$PHP_BIN" cron/alarms_evaluate_non_critical.php >> "$CRON_LOG_DIR/app-alarms-evaluate-non-critical.log" 2>&1
* * * * * . "$CRON_ENV_FILE" && cd "$APP_ROOT" && "$PHP_BIN" cron/alarms_dispatch.php >> "$CRON_LOG_DIR/app-alarms-dispatch.log" 2>&1

# Database retention
25 2 * * * . "$CRON_ENV_FILE" && cd "$APP_ROOT" && "$PHP_BIN" cron/retention_cleanup.php >> "$CRON_LOG_DIR/app-retention-cleanup.log" 2>&1
# Heartbeats of removed cron jobs; current jobs are never pruned by age.
35 2 * * * . "$CRON_ENV_FILE" && cd "$APP_ROOT" && "$PHP_BIN" cron/cron_history_cleanup.php >> "$CRON_LOG_DIR/app-cron-history-cleanup.log" 2>&1

# Filesystem rate-limit storage expires records but requires active deletion.
17 * * * * . "$CRON_ENV_FILE" && cd "$APP_ROOT" && "$PHP_BIN" cron/rate_limit_cleanup.php >> "$CRON_LOG_DIR/app-rate-limit-cleanup.log" 2>&1

# Maintenance
# Log maintenance covers the whole logs/ tree. logs/health/ is excluded: the
# ingestion health report rotates itself (size-based, config/data_retention.json).
# user-management-audit.log rotates weekly like the rest, but its compressed
# archives are never deleted (audit trail). Heartbeats are JSONL and unaffected.
# "*.log.[0-9]" sweeps numbered leftovers from the pre-logs/health rotation scheme.
0 2 * * * find "$LOG_DIR" -type f -name "*.log" ! -path "*/logs/health/*" -mtime +30 -delete 2>/dev/null
0 2 * * * find "$LOG_DIR" -type f \( -name "*.log.*.gz" -o -name "*.log.[0-9]" \) ! -path "*/logs/health/*" ! -name "user-management-audit.log.*.gz" -mtime +30 -delete 2>/dev/null
0 3 * * * find "$APP_ROOT/temp/api-cache" -name "*.json" -mtime +7 -delete 2>/dev/null
0 4 * * * find "$APP_ROOT/temp/sessions" -name "sess_*" -mtime +1 -delete 2>/dev/null
0 5 * * 0 find "$APP_ROOT/temp/templates-cache" -name "*.php" -mtime +7 -delete 2>/dev/null
# Rotate all logs recursively (skip empty files); escaped percent signs are required by cron.
0 6 * * 0 find "$LOG_DIR" -type f -name "*.log" ! -path "*/logs/health/*" -size +0c -exec sh -c 'for file do gzip -c "$file" > "${file}.$(date +\%Y\%m\%d).gz" && : > "$file"; done' sh {} + 2>/dev/null
```

Inspect the installed crontab with:

```
docker compose \
  --env-file config/db.config.env --env-file .env \
  exec -u www-data mata-web crontab -l
```

Application cron logs are under `logs/cron/`. The metrics ingestion report is `logs/health/collect_server_metrics_health.log`. The `find` lines at the end of the crontab delete logs and cache files older than their retention age; database retention is set in `config/data_retention.json`.

## Troubleshooting

### The web container does not start

```
ENV_NAME=dev # dev, demo, or prod
make status MODE=docker ENV="$ENV_NAME"
docker compose --env-file config/db.config.env --env-file .env logs mata-web
```

Check that `.env` and `config/config.env` contain the same `APP_ENV`, that the configuration files exist, and that the Docker daemon is running.

### MySQL is unavailable

```
ENV_NAME=dev # dev, demo, or prod
make wait-db MODE=docker ENV="$ENV_NAME"
docker compose --env-file config/db.config.env --env-file .env logs mata-db
```

Check `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD` in `config/db.config.env`. In Compose, the application host is `mata-db`, not `localhost`.

### Metrics are missing

Inspect the collector and health logs:

```
ENV_NAME=dev # dev, demo, or prod
make logs-web MODE=docker ENV="$ENV_NAME"
tail -f logs/health/collect_server_metrics_health.log
```

Then verify the node URL, shared credentials, database migrations, and that the server is not `disabled`. Use `make mata ARGS="metrics status"` for row counts.

### Code changes are not visible

The development image disables OPcache (`docker/php/php-dev.ini`). The production image enables it with `opcache.validate_timestamps = 0`, so edited files are never re-read while the container runs. Stale code in development usually means the development override was not applied:

```
make ps MODE=docker ENV=dev
docker compose --env-file config/db.config.env --env-file .env \
  exec mata-web php -i | grep opcache.enable
```

Rebuild with the development image, or reset the cache in place:

```
make dev-start
docker compose --env-file config/db.config.env --env-file .env \
  exec mata-web php -r "opcache_reset();"
```

### The web container restarts or reports unhealthy

The web container has a health check. Read its recorded output before the logs:

```
docker inspect --format='{{json .State.Health}}' mata-dashboard-web
docker compose --env-file config/db.config.env --env-file .env logs --tail=100 mata-web
```

Replace `mata-dashboard-web` if `COMPOSE_PROJECT_NAME` was changed. A restart loop hides startup errors; start the service in the foreground to see them:

```
docker compose --env-file config/db.config.env --env-file .env up mata-web
```

### Every page returns 500

The users file lives in the `mata_users` volume, not the repository. An empty volume makes every route return the users-file error page. Create a user with `make mata MODE=docker ENV=<env> ARGS="user create"`.

### Bind-mount permissions fail

Run the production permission repair target once after provisioning or when a host-side ownership problem is confirmed:

```
make prod-fix-perms MODE=docker ENV=prod
```

Do not make the whole repository writable by the web user. Only `logs/`, `temp/`, and the configured authentication directory need application write access.

See [Secure an installation](https://docs.mata.sh/guides/secure-application/index.md) for the production baseline.
