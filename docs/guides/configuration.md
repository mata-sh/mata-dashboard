# Configuration

MATA reads application configuration from `config/*.env` and monitoring
configuration from JSON files in `config/`. Files in `config.example/` provide
starting values and show the expected file shape.

`config/` belongs to your installation: the application repository ignores it
completely, so you can keep it in its own Git repository. See
[Version your config](version-config.md).

Run `make setup` first. It creates the core configuration, writes the selected
`APP_ENV`, and installs dependencies for native setups. Docker setup also
creates `.env` for Compose.

## Configuration files

| File | Purpose |
| --- | --- |
| `config/config.env` | Application, logging, OPA, rate-limit, and UI configuration |
| `config/db.config.env` | Database connection details |
| `config/mail.config.env` | Mailer DSN, sender, and recipients |
| `config/servers.json` | MATA Nodes monitored by the dashboard |
| `config/servers.secrets.json` | Node API keys for the servers in `servers.json` |
| `config/thresholds.json` | Metric thresholds and alarm candidate windows |
| `config/intervals.json` | Event cooldowns and delivery deduplication windows |
| `config/ttl.json` | Cache TTLs in seconds |
| `config/data_retention.json` | Metric, event, and alarm retention |
| `config/probes.json` | Optional node probe timeout and retry overrides |
| `config/notification_rules.json` | Optional alarm delivery channel selection rules |
| `config/menu.json` | Optional dashboard menu visibility overrides |
| `config/charts.json` | Optional dashboard chart metrics and timespans |
| `config/limits.json` | Optional transport limits for queued alarm deliveries |
| `config/channels.json` | Optional alarm channels besides the built-in email, such as another email address or a Telegram webhook |
| `config/advisories.json` | Optional [security advisory](security-advisories.md) sources, filters and digest |
| `config/policy/` | Optional [local alarm policies](../reference/alarms.md#local-policy-overrides), loaded by OPA |

`probes.json`, `notification_rules.json`, `menu.json`, `charts.json`,
`limits.json`, `channels.json`, and `advisories.json` are not created by setup. Their loaders use
built-in defaults when they are absent or empty (`{}`); without `channels.json`,
alarms are delivered by email only.
See `config.example/limits.json.example` for the optional rate-limit configuration
and its defaults. Setup does not create `limits.json`; when absent or empty, the
loader uses the same built-in limits. See the [alarm reference](../reference/alarms.md)
for details.

`menu.json` is a flat map of menu keys to visibility booleans. `charts.json`
uses a `dashboard` section to configure the chart's metric and timespan lists.
Each list replaces the complete built-in list; it does not merge with defaults.
Metric keys are `cpu`, `ram_percent`, `ram_used`, `disk_percent`, `disk_used`,
`file_count`, and `process_count`. Timespans accept a parseable range, `all`, or
`max`; `granularity` can be `auto`, `raw`, `hourly`, or `daily`. `refresh` must
be a JSON integer of at least 10 seconds. Invalid entries are ignored with a
warning, and an invalid file falls back to built-in chart defaults.

!!! info "Dashboard chart scope"

    `charts.json` configures only the dashboard overview chart. The per-server
    metrics modal keeps its built-in ranges.

`probes.json` controls timeout, retry count, and retry backoff for HTTP
reachability probes.

!!! warning "Numeric values"

    JSON integer fields require JSON integers. Numeric strings, floats, booleans,
    and `null` are invalid; out-of-range values are not clamped. Integer values in
    environment files use plain base-10 syntax without whitespace, a leading `+`,
    or leading zeroes.

When `MATA_USERS_FILE` is unset, `bin/mata user create` creates the fallback
`config/users.json` with restricted permissions. Do not create or edit it by
hand.

Credentials live only in `config/*.env` and `config/servers.secrets.json`.
Never commit those files, even when you version the rest of `config/`. Keep
production authentication data outside the deployment and web root with
`MATA_USERS_FILE`; see the [user management guide](user-management.md).

## Cron heartbeat retention

Heartbeat files live in a `cron/` directory beside the `APP_LOG_PATH` file
(default `logs/cron/`). Once a file exceeds `CRON_HEARTBEAT_MAX_BYTES` in
`config/config.env` (default `1048576`, valid range `131072`–`1073741824`), its
next write compacts it to the first heartbeat plus the complete records of the
latest 64 KiB, so cron status keeps its start time and inferred run interval.

!!! info "Daily cleanup"

    `cron_history_cleanup.php` deletes heartbeat files of jobs no longer in
    `cron/*.php`. Docker installs pick it up on rebuild; shared-hosting installs
    must add its line from `cron/crontab.shared-hosting.template`.

!!! warning "File access"

    Do not rotate heartbeat files externally; readers and writers lock the same
    inode. Compaction rewrites in place, so a crash mid-rewrite can damage that
    file's history.

## Environment files

`APP_ENV` accepts `dev`, `demo`, or `prod`. Native setup writes it to
`config/config.env`. Docker also stores it in `.env`, and both values must
match.

In `demo`, nodes are simulated. Set `DEMO_SEED` to a non-negative integer to
make the generated data repeat across requests, for example when taking
screenshots.

For a public demo, set `DEMO_LOGIN_USERNAME` and `DEMO_LOGIN_PASSWORD` to an
account created with `bin/mata user create`. The login page then shows these
credentials and prefills the form. Both are ignored outside `demo`.

!!! info "Required environment values"
    Configuration loading requires `DB_HOST`, `DB_PORT`, `DB_DATABASE`,
    `DB_USERNAME`, and `DB_PASSWORD`. Validation also requires `DOMAIN` and
    `SYM_MAILER_DSN`. Setup supplies placeholders; replace them before production.

Use `config.example/config.env.example` for the remaining application configuration.
See the [quickstart](quickstart.md) for alarm driver setup, the
[alarm reference](../reference/alarms.md) for decision behavior, and
[Secure an installation](secure-application.md) for production configuration.

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
SYM_MAILER_DSN="smtp://user:password@smtp.example.com:587"
SYM_FROM_EMAIL="monitor@example.com"
SYM_FROM_NAME="MATA Dashboard"
```

`MAIL_ALARM_RECIPIENTS` receives the built-in `email` alarm channel and direct
alarms about configuration and bootstrap failures, and is the default for
advisory digests and reports. To send selected alarms elsewhere, add an
[email channel](../reference/alarms.md#email-channels).

Use a non-delivering Symfony Mailer transport or a test SMTP service when
messages must not leave the system.

## Monitored servers

Follow [Add your first server](first-server.md) to pair node identities and
credentials. A server entry can also set dashboard monitoring configuration flags:

```json
{
  "prod-web-01": {
    "title": "Production web",
    "url": "https://app.example.com/",
    "api_url": "https://node.example.com/",
    "error_monitoring_enabled": true,
    "logsize_monitoring_enabled": true
  }
}
```

Each node's API key goes in `config/servers.secrets.json` under the same server
name, so `servers.json` holds no credentials:

```json
{
  "prod-web-01": { "api_key": "a-unique-shared-secret" }
}
```

Server names start with a letter or digit and use only letters, digits, `.`,
`_` and `-` (at most 64); they appear in dashboard URLs. Keys starting with `_`
are documentation and ignored. `title` and `api_url` are required, and every enabled server needs its
`api_key` in `servers.secrets.json`. `disabled` defaults to `false`.
Set it to `true` to exclude the node from polling and alarms. The dashboard
expects HTTPS node URLs. `allow_http` is available for local development only
and should not be used for production monitoring.

!!! warning "API keys in servers.json"

    A server that still sets `api_key` in `servers.json` is rejected with a
    `servers_config_invalid_entry` event. Move the key to
    `servers.secrets.json`. A key for a server that `servers.json` does not
    define is reported, and a malformed `servers.secrets.json` fails like a
    malformed `servers.json`.

!!! warning "Server state"

    `disabled` must be a JSON boolean. An invalid value excludes that server
    while valid servers continue running. `active`, `enabled`, and
    `monitoring_enabled` are unsupported and ignored. Replace them with
    `disabled` and invert the value. For example, `"active": false` becomes
    `"disabled": true`.

The two server configuration flags default to `true`:

- `error_monitoring_enabled` controls fatal-error event production.
- `logsize_monitoring_enabled` controls log-size event production.

Per-application settings live in the node's `config/apps.json` under its
`monitoring` object. See the [application error monitoring guide](application-error-monitoring.md).

## Thresholds, intervals, and deliveries

In `thresholds.json`, `server_data` and `app_data` map metrics to severity
boundaries such as `good`, `warn`, `critical`, and `alarm`. List them in
ascending order: a value takes the last level it exceeds, so a metric whose
limits do not increase is skipped. The `alarms` section maps event types to
event-count and time-window gates; `app_unreachable` also supports the
consecutive-failure strategy. See `config.example/thresholds.json.example` for
the full rule set.

`intervals.json` contains two different controls:

- `events.min_event_interval_minutes` suppresses duplicate event records.
- `alarms.min_alarm_interval_minutes` deduplicates equivalent channel
  deliveries. It does not delay a delivery.

`notification_rules.json` selects delivery channels through `defaults`,
per-event `event_types`, and `application_overrides`. Channels are the built-in
`email` plus any [channels](../reference/alarms.md#channels) defined in
`channels.json`. `limits.json` rate-limits
queued transport attempts and can schedule a retry. The full order is in the
[alarm decision and delivery flow](../reference/alarms.md).

## Cache and retention

Cache values in `ttl.json` are seconds. A value of `0` bypasses cache reads;
a negative value keeps cached entries indefinitely. See
`config.example/ttl.json.example` for the full key list and defaults.

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
that category. Aggregation and ingestion-health configuration lives in the same
file; see the [server metrics pipeline](../architecture/server-metrics-pipeline.md).

## Validate changes

`bin/mata config validate` reads every configuration file with the same loaders
the web app and cron use, and reports what they would warn about or ignore:

```bash
php bin/mata config validate                # all files
php bin/mata config validate servers.json   # one file and its companion
php bin/mata config validate --json         # machine-readable
make mata ARGS="config validate"            # as the right account, also in Docker
```

It exits `0` when the configuration is valid, `1` for warnings only (a skipped
entry, advice), and `2` for errors, including invalid or missing environment
values. Without the check, a broken file only shows up as a warning event while
the app runs on defaults; only a missing or invalid `servers.json` or
`servers.secrets.json` raises an alarm.

A full run also lists and checks each `.rego` file in `config/policy/`: a file
outside a `local` package is an error, a decision path that does not match the
local policies a warning (see
[Local policy overrides](../reference/alarms.md#local-policy-overrides)).

A file argument validates everything loaded with it: any of the three `.env`
files checks their merged environment, and either server file checks both. It
skips the policy checks.

Each problem names its sources without their values: a file and variable
(`config.env:DOMAIN`), a process environment value (`process:DOMAIN`), or an
unset variable (`missing:DOMAIN`). The JSON report lists them under `sources`,
and the files involved under `files`:

```json
{
  "files": ["config.env"],
  "level": "error",
  "type": "config.env.invalid",
  "message": "env.DASHBOARD_SERVER_COLUMNS must be >= 1",
  "sources": [
    {"kind": "file", "file": "config.env", "variable": "DASHBOARD_SERVER_COLUMNS"}
  ]
}
```

!!! info "Which account runs `bin/mata`"
    Every file has one writer. Your account (the one that runs Git) owns
    `config/`, and the web server reads it through its group. The web server
    owns `logs/`, `temp/`, and the users file, which it writes on every login.

    - `server add` and `server remove` write `config/`: run them as your account.
    - Every other command writes logs or the cache, or reads what the web
      server reads: run it as the web server user,
      `sudo -u www-data php bin/mata …` (`http` on Arch, `apache` on RHEL).
      Run `config validate` that way too, since a file your account can read
      may still be unreadable for the web server.

    `make mata ARGS="…"` picks the right account, natively and in Docker.
    `bin/mata` refuses to run as root.

`make doctor` first checks the installation: the selected `MODE` and `ENV`, the
three `.env` files and a matching `APP_ENV` (also in `.env` for Docker),
`config/`, `logs/`, `temp/`, and `servers.secrets.json`. It shows where a
symlinked `config/` points and whether it is a Git repository; natively, it
also checks PHP, Composer dependencies, and the OPA service. It then runs
`bin/mata doctor` as the web server user: config validity, writable `logs/` and
`temp/`, the users and secrets file modes, the database and pending migrations,
OPA health and a decision from `OPA_ALARM_DECISION_PATH` when it is the driver,
and whether the data-fetch cron runs.
