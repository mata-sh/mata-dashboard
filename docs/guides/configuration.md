# Configuration

MATA reads application settings from `config/*.env` and monitoring settings
from JSON files in `config/`. Files in `config/example/` provide starting values
and show the expected file shape.

Run `make setup` first. It creates the core configuration, writes the selected
`APP_ENV`, and installs dependencies for native setups. Docker setup also
creates `.env` for Compose.

## Configuration files

| File | Purpose |
| --- | --- |
| `config/config.env` | Application, logging, OPA, rate-limit, and UI settings |
| `config/db.config.env` | Database connection settings |
| `config/mail.config.env` | Mailer DSN, sender, and recipients |
| `config/servers.json` | MATA Nodes monitored by the dashboard |
| `config/thresholds.json` | Metric thresholds and alarm candidate windows |
| `config/intervals.json` | Event cooldowns and delivery deduplication windows |
| `config/ttl.json` | Cache TTLs in seconds |
| `config/data_retention.json` | Metric, event, and alarm retention |
| `config/probes.json` | Optional node probe timeout and retry overrides |
| `config/notification_rules.json` | Optional email delivery selection rules |
| `config/menu.json` | Optional dashboard menu visibility overrides |
| `config/limits.json` | Optional transport limits for queued alarm deliveries |

`probes.json`, `notification_rules.json`, `menu.json`, and `limits.json` are not
created by setup. Their loaders use built-in defaults when they are absent. Copy
the matching example to customize the first three. `limits.json` has no example;
see the [alarm reference](../reference/alarms.md) for its built-in limits.

`menu.json` is a flat map of menu keys to visibility booleans. `probes.json`
controls timeout, retry count, and retry backoff for HTTP reachability probes.

When `MATA_USERS_FILE` is unset, `make users-create` creates the fallback
`config/users.json` with restricted permissions. Do not create or edit it by
hand.

Do not commit populated configuration files. They contain credentials and are
ignored by Git. Keep production authentication data outside the deployment and
web root with `MATA_USERS_FILE`; see the [user management guide](user-management.md).

## Environment files

`APP_ENV` accepts `dev`, `demo`, or `prod`. Native setup writes it to
`config/config.env`. Docker also stores it in `.env`, and both values must
match.

!!! info "Required environment values"
    Configuration loading requires `DB_HOST`, `DB_PORT`, `DB_DATABASE`,
    `DB_USERNAME`, and `DB_PASSWORD`. Validation also requires `DOMAIN` and
    `SYM_MAILER_DSN`. Setup supplies placeholders; replace them before production.

Use `config/example/config.env.example` for the remaining application settings.
See the [quickstart](quickstart.md) for alarm driver setup, the
[alarm reference](../reference/alarms.md) for decision behavior, and
[Secure an installation](secure-application.md) for production settings.

### Database

Set the required connection values in `config/db.config.env`:

```dotenv
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=mata_dashboard
DB_USERNAME=mata_user
DB_PASSWORD=replace-this
```

The dashboard can render basic pages without a database, but migrations, event
history, alarm processing, and scheduled metric persistence require a working
MySQL or MariaDB connection.

### Mail

Mail uses Symfony Mailer. Set the required DSN, then configure the sender and
recipients in `config/mail.config.env`:

```dotenv
MAIL_ALARM_RECIPIENTS="alarms@example.com"
MAIL_DEV_RECIPIENTS="developers@example.com"
SYM_MAILER_DSN="smtp://user:password@smtp.example.com:587"
SYM_FROM_EMAIL="monitor@example.com"
SYM_FROM_NAME="MATA Dashboard"
```

Use a non-delivering Symfony Mailer transport or a test SMTP service when
messages must not leave the system.

## Monitored servers

Follow [Add your first server](first-server.md) to pair node identities and
credentials. A server entry can also set dashboard monitoring flags:

```json
{
  "prod-web-01": {
    "title": "Production web",
    "url": "https://app.example.com/",
    "api_url": "https://node.example.com/",
    "api_key": "a-unique-shared-secret",
    "error_monitoring_enabled": true,
    "logsize_monitoring_enabled": true
  }
}
```

`title`, `api_url`, and `api_key` are required. `disabled` defaults to `false`.
Set it to `true` to exclude the node from polling and alarms. The dashboard
expects HTTPS node URLs. `allow_http` is available for local development only
and should not be used for production monitoring.

!!! warning "Server state"

    `disabled` must be a JSON boolean. An invalid value excludes that server
    while valid servers continue running. `active`, `enabled`, and
    `monitoring_enabled` are unsupported and ignored. Replace them with
    `disabled` and invert the value. For example, `"active": false` becomes
    `"disabled": true`.

The two server-scoped application flags default to `true`:

- `error_monitoring_enabled` controls fatal-error event production.
- `logsize_monitoring_enabled` controls log-size event production.

Per-application settings live in the node's `config/apps.json` under its
`monitoring` object. See the [application error monitoring guide](application-error-monitoring.md).

## Thresholds, intervals, and deliveries

In `thresholds.json`, `server_data`, `app_data`, and `sql` map metrics to
severity boundaries such as `good`, `warn`, `critical`, and `alarm`. The
`alarms` section maps event types to event-count and time-window gates.
`app_unreachable` also supports the consecutive-failure strategy. See
`config/example/thresholds.json.example` for the full rule set.

`intervals.json` contains two different controls:

- `events.min_event_interval_minutes` suppresses duplicate event records.
- `alarms.min_alarm_interval_minutes` deduplicates equivalent channel
  deliveries. It does not delay a delivery.

`notification_rules.json` selects delivery channels through `defaults`,
per-event `event_types`, and `application_overrides`. `limits.json` rate-limits
queued transport attempts and can schedule a retry. The full order is in the
[alarm decision and delivery flow](../reference/alarms.md).

## Cache and retention

Cache values in `ttl.json` are seconds. A value of `0` bypasses cache reads;
a negative value keeps cached entries indefinitely. See
`config/example/ttl.json.example` for the full key list and defaults.

Retention is configured in `data_retention.json`:

```json
{
  "metrics": {
    "raw_retention_days": 30,
    "hourly_retention_days": 90,
    "daily_retention_days": 365
  },
  "events": { "retention_days": 180 },
  "alarms": { "retention_days": 365 }
}
```

The scheduled cleanup job owns deletion. A value of `0` disables cleanup for
that category. Aggregation and ingestion-health settings are in the same file;
see the [server metrics pipeline](../architecture/server-metrics-pipeline.md).

## Validate changes

`make doctor` is an installation sanity check, not a configuration parser.
It confirms the selected `MODE` and `ENV`, that `config/config.env`,
`config/db.config.env`, and `config/mail.config.env` exist, that `APP_ENV`
matches `ENV` (and `.env` for Docker), and that `config/`, `logs/`, and
`temp/` are present. Native mode also checks for PHP, Composer
dependencies, and whether the OPA service is running. It does not read
JSON files or validate keys. Loaders record configuration events when a
JSON file is missing or invalid.

```bash
make doctor
make db-status
```
