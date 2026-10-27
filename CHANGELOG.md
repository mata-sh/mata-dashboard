# Changelog

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- `channels.json` accepts `"type": "email"` channels with their own `recipients`, so `notification_rules.json` can send selected alarms, such as `advisories.matched`, to another address than `MAIL_ALARM_RECIPIENTS`. Direct alarms keep using the built-in `email` channel.

### Fixed
- A missing sender address now reports `SYM_FROM_EMAIL`, the variable the dashboard reads, instead of `MAIL_FROM_EMAIL`.

### Removed
- `MAIL_DEV_RECIPIENTS` and `MAIL_TEST_RECIPIENTS`: no mail was ever sent to them; the settings page no longer lists them. `alarm test email` mails `MAIL_ALARM_RECIPIENTS`. **Action:** none; remaining entries in `mail.config.env` are ignored.

## [0.9.0] - 2026-10-26

### Added
- The alarms table has an "Acknowledge all" button that acknowledges all alarms, regardless of active filters.
- Security advisory monitoring: `config/advisories.json` lists RSS/Atom feeds, GitHub repository advisories, IMAP mailboxes, OSV.dev packages and CSAF providers with keyword and severity filters. New relevant advisories are recorded once as `advisories.matched` events that alarm through the usual decision driver and notification rules (`"notify": false` only lists them); failing sources and a stopped poller warn. `bin/mata advisory:sources|fetch|report` and the `digest` setting mail PDF, Markdown, HTML or JSON reports. **Action:** copy `config.example/advisories.json.example`, run `bin/mata migrate up`, then `bin/mata advisory:fetch` for the baseline; shared hosting adds the two `advisories_*` lines from `cron/crontab.shared-hosting.template`.
- An **Advisories** page lists sources and advisories with their review status and downloads reports.
- The Advisories table reviews every advisory the filters show at once (**Bulk review**, next to the relevant-only toggle), with an optional shared note; advisories already in that status keep their review.
- Local alarm policies: OPA also loads `config/policy/`, so overrides are versioned with the configuration and build on the shipped `policy/alarms.rego` in a `local.*` package; `OPA_ALARM_DECISION_PATH=/v1/data/local/alarms/decision` selects them. `config validate` rejects local files outside a `local` package and warns when the decision path and local policies do not match; `doctor` checks that the configured decision path answers; `make opa-test` tests both directories. **Action:** native installs with the OPA systemd unit run `make opa-install-service` once; Docker picks it up on the next `make dev-start` or `make prod-start`.
- `bin/mata` operator CLI (`make mata ARGS="…"` runs it as the web server user): `server add|list|test|remove`, `config validate`, `status` (Nagios exit codes), `doctor`, `user …`, `migrate status|up|down|check`, `alarm test|explain`, `metrics status` and `--version`, most with `--json`. Docker images built with `make` carry the version, also as the `org.opencontainers.image.version` label.
- Read-only data commands for scripts and coding agents: `bin/mata server show|info`, `apps list|sessions|readme`, `logs show|files`, `processes show`, `cronjobs show`, `libraries list`, `audit show`, `db show|processes`, `events list|show`, `alarms list` and `metrics series`. Node data comes from the dashboard's cache unless `--live` asks the node; with `--json` each document carries `source`, `observed_at`, `cache` and `truncated`, and `logs show` says per file whether the node cut off older entries.
- `bin/mata evidence` bundles events, logs, metrics and processes around an event or a time window into one JSON document.
- `--save` stores a read-only command's result with an optional `--label` and `--note`, `metrics status` included; `bin/mata saved list|show` finds it again. **Action:** run `bin/mata migrate up`.
- `curl -fsSL https://mata.sh/install.sh | sh` installs the newest release with Docker for production; `--native` prepares a native checkout. Each release attaches `install.sh`.
- `make uninstall` removes a Docker install, including the database, `logs/` and `temp/` that only the containers can delete.
- A summary bar above the server grid counts servers and apps by state and filters the cards; the cards reload from the cache every `DASHBOARD_AUTO_REFRESH_SECONDS` (default 60).
- Demo: `DEMO_SEED` makes the generated data repeatable, and `DEMO_LOGIN_USERNAME` and `DEMO_LOGIN_PASSWORD` prefill the login form.
- Coding agents: the "Install with a coding agent" guide is a runbook, the docs site publishes `llms.txt` and `llms-full.txt` (linked from the README and the guide), and `config.example/AGENTS.md` gives rules for editing a config repository.
- Repeated log messages: with nodes that group them, the logs modal and `logs show` show each message once with its count, "(×42)", and the JSON of `logs show` and `evidence` carries `count` and `first_seen`. Older nodes keep working, one entry per occurrence.
- Application cards show bundled framework and CMS logos, including Shopware, Joomla, CakePHP, Sylius, Craft CMS, Statamic, Contao, Sulu, Neos, October CMS, Yii and CodeIgniter, with the name and optional version on hover. Magento is labelled with a generic icon. Custom names use an escaped label and a generic icon, also used if a bundled logo is missing. Requires nodes reporting `framework` in `/apps`; older nodes and apps without a framework show no logo.

### Changed
- The settings page groups file-backed values under "Loaded Configuration", with each section naming its source file.
- The log modal's entry-limit hint uses orange text for better visibility.
- Cron heartbeat history now compacts at `CRON_HEARTBEAT_MAX_BYTES` (default 1 MiB), keeping its first entry and recent history; the new daily `cron_history_cleanup.php` job removes heartbeats of deleted jobs. **Action:** shared hosting adds the `cron_history_cleanup.php` line from the crontab template.
- Database cards show each named connection as a collapsible row with its engine, metrics and databases; one failed connection no longer hides the others. `database_unreachable` lists all failed connections; `database_connections_too_high` uses the busiest. **Action:** update dashboard and nodes together; older nodes' database data is rejected.
- The non-Docker mode is now `MODE=native`; `local` is no longer accepted. **Action:** in `.mata.mk`, change `MODE := local` to `MODE := native`, or rerun `make setup`.
- Node API keys move from `config/servers.json` to `config/servers.secrets.json`. **Action:** move each `api_key` there; the file needs mode 0640 and the web server's group.
- Server names in `servers.json` follow the `bin/mata server add` rule; other servers are dropped and reported. **Action:** rename them in both server files and the node's `DASHBOARD_SERVER_ID`.
- A `thresholds.json` metric whose limits decrease in the listed order is skipped and reported. **Action:** run `bin/mata config validate`.
- Config load events are renamed: `<file>_config_not_found` to `<file>_config_invalid` and `<file>_config_validation_error` to `<file>_config_invalid_entry`. **Action:** run `bin/mata migrate up` and rename these keys in `notification_rules.json`.
- `config/` is instance-owned: the application repository ignores all of it, templates moved to `config.example/`, and the development container no longer changes its owner or mode on start. **Action:** if Git reports dubious ownership, run `sudo chown -R "$(id -un)" config/` once.
- Alarm emails and advisory reports use the dashboard's severity colors and a plain header; error and warning alarms no longer share one color.
- An invalid `MAIL_DASHBOARD_URL` (not absolute http(s), or with `user:pass@`) no longer fails alarm delivery: messages omit the dashboard link, and `bin/mata config validate` and the settings page flag it.
- `make setup` runs without prompts when there is no terminal, and Docker setup replaces the placeholder `MYSQL_ROOT_PASSWORD` and `DB_PASSWORD` with generated values.
- `make db-migrate` runs `bin/mata migrate up` as the web server user; `make doctor` shows where `config/` points and whether it is a Git repository.
- The server grid adapts to the screen width: `DASHBOARD_SERVER_COLUMNS` is now a maximum (default 4).
- An empty `config/probes.json` means the built-in probe defaults.
- The demo shows a mostly healthy fleet with a single incident instead of every failure state at once.
- Documentation is published at <https://docs.mata.sh>.
- The dashboard is always dark; it no longer switches to Bulma's unstyled light theme when the system prefers light mode.

### Removed
- `scripts/alarm_channel_test.php`, `scripts/server_metrics_diagnostic.php`, `make users-*`, `db-rollback`, `db-status`, `db-check`, `db-hash-password` and the scripts they ran; use `bin/mata alarm test`, `metrics status`, `user …` and `migrate …`.

### Fixed
- Advisory details open when clicking anywhere on a table row, not just its title.
- App names in dashboard server cards now have 3px of right margin.
- `doctor` no longer mistakes inaccessible users files for missing ones; run it as the application user to check access before creating users.
- Docker: two dashboard stacks on one host no longer reach each other's MySQL and OPA; only the web container joins the shared `mata-network`. **Action:** recreate the containers.
- Cron jobs honour `LOG_PATH` and `TEMP_DIR`; they wrote to the default `logs/` and `temp/`, so the dashboard showed stale data and cron jobs as not running.
- Production Docker: fresh installs build, run their cron jobs, and allow sign-in and user commands.
- The libraries and logs tables no longer empty themselves when the notification bell refreshes.
- Alarm text mails no longer join the metric line and "Last seen" when the metric has a detail.
- When the account may not use Docker, `make` says so and names the fix instead of reporting "Docker daemon is unavailable".
- The users-file error page links to the users-file setup guide instead of a missing page.
- Server and app route parameters reject `.` and `..`, which could otherwise reach a node API path as a directory traversal.
- Node `api_key`s are no longer copied into the database; `servers.metadata` keeps only the title, colors and info.

## [0.8.0] - 2026-10-11

Pairs with MATA Node 0.4.0.

### Added
- App title tooltips show the node-reported description; the server card's process count opens its history in the server metrics modal
- The documentation site includes the MATA Node reference
- Database alarms `database_unreachable` and `database_connections_too_high` (default at 80 % of `max_connections`, `server_data.database_connections_percent`)
- Users-file alarms: `user_account_locked`, `user_account_changed` (create, delete, role change; first run records a baseline) and `users_file_insecure` (group/other access)
- `library_outdated_security` alarm for new Composer security advisories (critical for `critical`/`high`). **Action:** run the migration and add `cron/libraries_audit.php` (every 6 hours) to your crontab. Needs MATA Node 0.4.0; disable per app with `monitoring.libraryaudit: false` in `apps.json`
- Webhook alarm channels (Telegram, Slack, Discord): define them in the optional `config/channels.json` (see `config/example/channels.json.example`), put each URL in `config/config.env` (e.g. `WEBHOOK_TELEGRAM_URL`), and reference the channel in `notification_rules.json`. Test with `php scripts/alarm_channel_test.php <channel>`. Emergency alarms stay email-only.
- `limits_unknown_channel` warning when `limits.json` names an unconfigured channel

### Changed
- `manage-users.php` rejects invalid usernames, unknown roles, and passwords under 12 characters or over 72 bytes (except in `demo`); existing accounts are unaffected
- Configuration problems are recorded once per 30 minutes per problem, respect `EVENTS_ENABLED`, and fall back to `logs/app.log` when the database is unavailable. Missing `probes.json` and `limits.json` are no longer reported; a malformed `limits.json` is
- An unreadable or empty `thresholds.json` records a warning instead of a critical alarm
- `too_many_sql_connections` is renamed to `database_connections_too_high` and the `sql` section of `thresholds.json` is removed. **Action:** rename the key in `thresholds.json` and `intervals.json`
- Docker images use `php:8.3-apache-trixie` and the `*-rebuild` targets pull the newest base. **Action:** change `PHP_VERSION=8.3.14` in `.env` to `8.3`
- Database cards use the node's `/databases/overview`, fetch process lists live, and name the missing privilege when the monitoring account sees only its own sessions. Requires MATA Node 0.3.0
- `notification_rules.json` skips unconfigured channels with a `notification_rules_unknown_channel` warning instead of discarding all rules
- The `builtin` alarm decision driver delivers on every selected channel, not just email
- `users.json` is validated on load: a missing required field or non-integer counter shows the authentication error page naming the entry.
- `users.json` requires `auth_revision` on every account, and the remember-me hash is stored as `remember_token_hash` instead of `remember_token`. **Action:** recreate accounts that lack `auth_revision` with `manage-users.php`, or add a random value (`openssl rand -hex 32`).

### Removed
- The unlinked process-count modal (`/watch/modal/{server}/process-count`)

### Fixed
- The users file is refused when group-accessible (e.g. `0640`) and accepted when owner-only with the execute bit. **Action:** `chmod 600` the users file if sign-in fails after upgrading
- Database card refresh button and cache-age tooltips
- Resolved configuration warnings are cleared reliably
- Notices with a saved choice ("never", "snooze") close only after the server stores it
- `servers_config_not_found` names the actual cause (unreadable, invalid JSON, empty)
- A failed `servers.json` database sync is recorded as a `settings_sync_error` event
- Alarm titles with a dotted area prefix read `Server metrics: …` instead of `Server metrics.…`
- Modals open with the node's error instead of not opening; node 429 and 403 rejections are reported instead of showing as missing data, and 5xx details stay in `logs/app.log`
- Single-app Composer audits (`/watch/audit/run/{server}/{app}`) require `POST` with a CSRF token
- Sign-in with an unknown or locked username takes as long as a wrong password, so timing no longer reveals accounts
- Parallel wrong passwords all count towards the lockout; concurrent changes to other accounts no longer break sign-in or `manage-users.php`
- Reading `users.json` no longer takes a lock, so page views no longer create or chmod `users.json.lock`

## [0.6.0] - 2026-10-09

First public release. Versions up to 0.5.0 were developed privately; this entry
summarizes what landed since then.

### Added
- Node monitoring: health, sessions, cron jobs, per-database status and process views
- Application monitoring: status-endpoint probes with retries, error-log monitoring with log-file viewer, per-app settings, shared transport failure detection
- Server metrics: collection, hourly/daily aggregation, history charts, CPU and RAM thresholds
- Alarms: logical alarms with per-channel deliveries, notification rules, acknowledgement, templated emails with event details
- Alarm decisions via OPA policies with a built-in fallback driver (`ALARM_DECISION_DRIVER`) and persisted decision traces
- Composer audit dashboard for vulnerable and abandoned packages
- Guided `make setup`, unified native and Docker workflows, shared-hosting crontab and logrotate templates
- Standardized cron logging, scheduled data retention cleanup, dashboard cache warming
- Settings overview, configurable menu, instance branding, demo mode
- Security: hardened sessions, configurable security headers and CSP, opt-in HSTS, trusted proxy handling, IP whitelist on application routes, request rate limiting

### Changed
- Alarms are split into logical alarms and deliveries; run database migrations when upgrading
- Node requests use per-request HMAC tokens and require a matching MATA Node
- User data is stored outside the application directory
- The `live` environment is renamed to `prod`
- Alarm threshold window key renamed; legacy keys are no longer read
- Obsolete mail and monitoring settings and encryption helpers removed
- Password and role changes revoke existing sessions and remember-me logins; accounts from earlier versions sign in with their password once after upgrading

## [0.5.0] - 2026-01-12

### Changed
- Root namespace renamed from `Watcher` to `Mata`
- Database tables renamed (`dashboard_*` → shorter names); the `alarms.event_id` foreign key is removed
- Demo mode uses static mock event data

### Fixed
- Race conditions when writing the users file

## [0.4.0] - 2026-01-06

### Added
- CI and release workflows (with CycloneDX SBOM) and Dependabot
- IP whitelisting for login (`IP_WHITELIST_ENABLED`, `ALLOWED_IPS`) and rate limiting with file or Redis storage

### Fixed
- Docker database port mapping (3307:3306) and Monolog initialization in cron jobs

## [0.3.0] - 2026-01-05

### Added
- `manage-users.php` safety checks (`CLI_STRICT_USER_CHECK`, `CLI_ALLOWED_USERS`) and audit log in `logs/user-management-audit.log`
- `allow_http` and `suppress_http_warning` flags for node connections
- `servers.json` entries whose keys start with `_` are skipped

## [0.2.1] - 2026-01-03

### Changed
- `make dev`/`make prod` renamed to `make dev-start`/`make prod-start`; `db.config.example` renamed to `db.config.env.example`, and database settings moved there from `config.env`

## [0.2.0] - 2026-01-03

### Added
- Config files are created from templates on first run, with permissions `600`
- `make setup` offers Apache, Nginx and Caddy configs (`docs/examples/webserver-configs/`)

### Changed
- The Docker Apache vhost rewrites URLs itself; `.htaccess` files are removed

## [0.1.0] - 2026-01-02

### Added
- Error pages for configuration initialization failures

## [0.0.1] - 2025-08-22
