# Changelog

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- `curl -fsSL https://mata.sh/install.sh | sh` installs the newest release with Docker for production; `--native` prepares a native checkout. Each release attaches `install.sh`.
- `make uninstall` removes a Docker install, including the database, `logs/` and `temp/` that only the containers can delete.
- The "Install with a coding agent" guide is a runbook for agents; the docs site publishes `llms.txt`.
- `DEMO_SEED` makes the generated demo data repeatable
- A summary bar above the server grid counts servers and apps by state and filters the cards.
- Server cards reload from the cache every `DASHBOARD_AUTO_REFRESH_SECONDS` (default 60).
- In `demo`, `DEMO_LOGIN_USERNAME` and `DEMO_LOGIN_PASSWORD` prefill the login form.
- `config.example/AGENTS.md` gives coding agents rules for editing a config repository.
- `bin/mata alarm test [channel]` replaces `scripts/alarm_channel_test.php`; `bin/mata alarm explain [server]` shows recent alarm decisions.
- `bin/mata metrics status` replaces `scripts/server_metrics_diagnostic.php`.
- `bin/mata user …`, `db …` and `doctor` commands, all with `--json`.
- `bin/mata status` reports node health and cron jobs with Nagios exit codes.
- `bin/mata --version` shows the installed release, taken from git tags; Docker images built with `make` include it, also as the `org.opencontainers.image.version` label.
- `bin/mata server add|list|test|remove` manage `servers.json` and `servers.secrets.json`.
- `bin/mata config validate` checks every config file with the app's own loaders.
- `bin/mata` is the new operator CLI; `make mata ARGS="…"` runs it as the web server user.
- `make doctor` shows where `config/` points and whether it is a Git repository.

### Changed
- The non-Docker mode is now `MODE=native` (was `MODE=local`); `local` is no longer accepted. Native installs set up earlier: change `MODE := local` to `MODE := native` in `.mata.mk`, or rerun `make setup`.
- `make setup` runs without prompts when there is no terminal, so scripts and coding agents can install MATA.
- Docker setup replaces the placeholder `MYSQL_ROOT_PASSWORD` and `DB_PASSWORD` with generated values.
- `make db-migrate` runs `bin/mata db migrate` as the web server user.
- Documentation is published at https://docs.mata.sh
- The demo shows a mostly healthy fleet with a single incident instead of every failure state at once.
- The server grid adapts to the screen width: `DASHBOARD_SERVER_COLUMNS` is now a maximum (default 4).
- Node API keys move from `config/servers.json` to `config/servers.secrets.json`. **Action:** move each `api_key` there; the file needs mode 0640 and the web server's group.
- The application repository ignores all of `config/`; templates moved to `config.example/`.
- Config load events use one naming scheme: `<file>_config_invalid`
- Server names in `servers.json` follow the `bin/mata server add` rule; other servers are dropped and reported. **Action:** rename them in both server files and the node's `DASHBOARD_SERVER_ID`.
- An empty `config/probes.json` means the built-in probe defaults.
- The development container no longer changes the owner or mode of `config/` on start. **Action:** if Git reports dubious ownership, run `sudo chown -R "$(id -un)" config/` once.
- A `thresholds.json` metric whose limits decrease in the listed order is skipped and reported. **Action:** run `bin/mata config validate`.

### Removed
- `config/phinx.php`, which `make db-migrate` never used.
- `make users-*`, `db-rollback`, `db-status`, `db-check`, `db-hash-password` and the scripts they ran; use `bin/mata user …` and `bin/mata db …`.

### Fixed
- Docker: two dashboard stacks on one host no longer reach each other's MySQL and OPA. Both now sit on a network private to each Compose project, and only the web container joins the shared `mata-network` to reach nodes. Recreate the containers for this to apply.
- `config validate` reports actual file, process, and missing-input sources once per problem; each JSON problem lists every implicated file in `files`.
- Cron jobs honour `LOG_PATH` and `TEMP_DIR`; they wrote caches, locks and heartbeats to the default `logs/` and `temp/`, so the dashboard showed stale data and cron jobs as not running
- Production Docker: sign-in and user commands work; PHP's `open_basedir` excluded the users volume
- Production Docker runs its cron jobs on a fresh install; they failed until `logs/cron` existed on the host
- Production Docker builds leave out `data/`, which made the build fail once MySQL owned it
- When the account may not use Docker, `make` says so and names the fix instead of reporting "Docker daemon is unavailable"
- The users-file error page links to the users-file setup guide instead of a missing page
- App environment badges (PROD, TEST, …) center their text vertically

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
