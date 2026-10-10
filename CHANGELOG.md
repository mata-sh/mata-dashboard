# Changelog

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Webhook alarm channels, starting with Telegram: define channels in the optional `config/channels.json` (see `config/example/channels.json.example`; each entry has `"type": "webhook"`, and `email` stays built in), put each URL in `config/config.env` (for example `WEBHOOK_TELEGRAM_URL`), and list the channel name in `notification_rules.json`. `php scripts/alarm_channel_test.php <channel>` sends a test alarm. Emergency alarms for configuration failures stay email-only

### Changed
- `notification_rules.json` accepts any channel name. A channel that is not configured is skipped and reported as a `notification_rules_unknown_channel` warning; previously an unknown channel discarded the whole file's rules
- The `builtin` alarm decision driver delivers on every channel the notification rule selects instead of email only

### Fixed
- Modals (README, logs, server info, cronjobs, database processes) now open with the reason when the node reports an error, instead of not opening at all. Node rate-limit (429) and IP-allowlist (403) rejections are now reported instead of showing as missing data. Node 5xx errors show only their short label in the UI; hosts, driver errors and shell commands appear only in `logs/app.log`
- Running a Composer audit for a single app (`/watch/audit/run/{server}/{app}`) now requires `POST` with a CSRF token, so another site can no longer start audits through a logged-in browser
- Signing in with an unknown or locked username now takes as long to fail as a wrong password, so response times no longer reveal which accounts exist or are locked

## [0.8.0] - 2026-10-10

Pairs with MATA Node 0.4.0.

### Added
- App cards show the application description reported by the node in the app title tooltip
- Clicking the process count on a server card opens the server metrics modal with the total process count history
- The documentation site includes the MATA Node reference (configuration, Docker, custom log parsers), synced from mata-node at build time

- Database alarms: `database_unreachable` when the node cannot reach its database server, and `database_connections_too_high` when connections reach the `server_data.database_connections_percent` alarm level (default 80 % of `max_connections`). Both are checked by the server metrics cron
- Security alarms from the users file: `user_account_locked` (warning) when failed logins lock an account, `user_account_changed` (warning) when an account is created, deleted or given a new role, and `users_file_insecure` (critical) when the users file is readable or writable by group or others. All are checked every 5 minutes by `servers_data_fetch`; account changes are compared against `temp/users-file-monitor.json`, and the first run only records a baseline
- Library security alarms: `library_outdated_security` when a Composer audit reports a security advisory the app did not have before (critical for `critical`/`high` advisories, otherwise warning). The new `cron/libraries_audit.php` job runs every 6 hours and asks the node for a fresh audit only once the cached one exceeds the `audit` TTL. Run the database migration and add the job to your crontab. Requires MATA Dashboard 0.8.0 paired with MATA Node 0.4.0 before setting `monitoring.libraryaudit` in `apps.json`; it defaults to `true`, and `false` disables per-app Composer auditing and library security events.

### Changed
- `manage-users.php create` rejects usernames other than 1 to 64 letters, digits, dots, underscores or hyphens, and roles other than `viewer` and `admin` (previously a typo such as `admn` created a user without admin rights); existing accounts are unaffected
- A missing `probes.json` or `limits.json` is no longer reported, since both are optional. A malformed `limits.json` is now reported as a dashboard configuration event instead of only in `logs/app.log`
- Configuration problems are recorded once per 30 minutes per distinct problem instead of on every page load and cron run, and are skipped when `EVENTS_ENABLED=false`
- Events that cannot be stored, because the database is unavailable or a write fails, are written to `logs/app.log`; a database error while recording a configuration problem no longer stops the page or cron job
- An unreadable or empty `thresholds.json` now records a warning and uses the built-in thresholds instead of raising a critical alarm email; monitoring was already continuing with those defaults
- `manage-users.php create` / `passwd` reject passwords shorter than 12 characters or longer than 72 bytes (except in `demo`); existing passwords are unaffected
- The unused `too_many_sql_connections` alarm type is renamed to `database_connections_too_high`, and the unread `sql` section of `thresholds.json` is removed; rename the key in your `thresholds.json` and `intervals.json`
- Docker images build on the latest PHP 8.3 release on Debian 13 (`php:8.3-apache-trixie`) with the latest Composer 2, and `make dev-rebuild` / `make prod-rebuild` pull the newest base image. If your `.env` sets `PHP_VERSION=8.3.14`, change it to `8.3`; no Debian 13 image exists for 8.3.14
- Database cards read MATA Node's `/databases/overview` and show connection numbers once per database server; the process list opens per database or for the whole server, is fetched live, and names the privilege the monitoring account lacks when it only sees its own sessions (MariaDB `PROCESS`, PostgreSQL `pg_read_all_stats`). Nodes without database monitoring show the section as not configured. Requires MATA Node 0.3.0 or later

### Removed
- The unlinked process-count modal (`/watch/modal/{server}/process-count`); its Refresh button replaced the modal with raw JSON. Process history now lives in the server metrics modal

### Fixed
- The dashboard now refuses a users file that is readable or writable by its group (for example mode `0640`), as it already did for world-readable files, and no longer refuses an owner-only file with the execute bit set. If the dashboard shows an authentication error after upgrading, run `chmod 600` on the users file
- Cache-age tooltips on server, app and database cards no longer append a stray "s" (for example "5 minutes s ago"), and the database card tooltip shows the overview's cache age instead of blank values
- The refresh button on database cards reloads that server's card; it previously did nothing
- Resolved configuration warnings are cleared reliably; previously the cleanup query failed unless the resolved warnings were listed first
- Dashboard notices with a saved choice (such as "never" or "snooze") now close only after the server stores it; a failed request leaves the notice visible
- The `servers_config_not_found` alarm names the actual cause (unreadable file, invalid JSON, or an empty file); previously it said "file not found" even when the file existed but contained a syntax error
- A failed sync of `servers.json` to the database is now recorded as a `settings_sync_error` dashboard event; previously it only reached `logs/app.log`

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

### Breaking Changes
- Changed root namespace from `Watcher` to `Mata` across entire codebase
- Removed `Loggable` trait in favor of direct `LoggerInterface` constructor injection
- `EventAggregatorService` now implements `EventAggregatorServiceInterface`
- Moved alert-related services from `Services\Foundation` to `Services\Alert`
- Moved event-related services from `Services\Foundation` to `Services\Event`
- Moved notification services from `Services\Foundation` to `Services\Notification`
- Removed foreign key constraint between `alarms.event_id` and `events.id`

### Added
- Added `MockEventAggregatorService` for demo/testing scenarios with static event data
- Added `EventAggregatorServiceInterface` to allow swapping real/mock implementations
- Added `app_env` field to `applications` table
- Added composite index `idx_event_alarm_eval` on `events` table
- Added `*.lock` to `.gitignore`
- Added file locking to `Auth` class for concurrent user file access
- Added `treatPhpDocTypesAsCertain: false` to PHPStan configuration
- Added logging to `AlertDispatcher`, `AlertEmailSender`

### Fixed
- Fixed user file race conditions in `Auth` with exclusive file locking
- Fixed unsafe user file initialization in `manage-users.php` with atomic writes
- Removed outdated SMTP dev config examples from `mail.config.env.example`
- Improved alert email subject format to show app and server more clearly
- Streamlined `CronJob` constructor to require logger as first parameter
- Database table renames for consistency (`dashboard_*` → simpler names)

## [0.4.0] - 2026-01-06

### Breaking Changes
- None

### Added
- GitHub Actions CI workflow (composer validate, security audit, PHPStan level 5, PHP-CS-Fixer, PHPUnit)
- GitHub Actions Release workflow with SBOM generation (CycloneDX)
- Dependabot configuration for monthly composer dependency updates
- CLI security checks for user management with audit logging
- HTTP connection security validation with `allow_http` and `suppress_http_warning` configuration flags
- IP whitelisting for login protection (`IP_WHITELIST_ENABLED`, `ALLOWED_IPS`)
- Rate limiting support with file/Redis storage backends
- Server entry filtering (skip underscore-prefixed keys in `servers.json`)

### Fixed
- Guzzle downgraded from 8.0.x-dev to ^7.0 for stability
- Composer installation order in `make setup` (now runs after config prompts)
- Database docker port mapping (3307:3306)
- Monolog initialization in cronjobs
- Array access on potentially undefined server config keys in `ServerRenderer.php`

## [0.3.0] - 2026-01-05

### Breaking Changes
- None

### Added
- GitHub Actions CI workflow (composer validate, security audit, PHPStan level 5, PHP-CS-Fixer, PHPUnit)
- GitHub Actions Release workflow with SBOM generation (CycloneDX)
- Dependabot configuration for monthly composer dependency updates
- CLI security checks for `manage-users.php` (user authorization, SSH warnings, production confirmations)
- Audit logging for user management operations in `logs/user-management-audit.log`
- `CLI_STRICT_USER_CHECK` and `CLI_ALLOWED_USERS` config options
- CLI mode support in `Auth` class to bypass session/role checks
- HTTP connection security validation with `allow_http` and `suppress_http_warning` flags
- Server entry filtering (skip underscore-prefixed keys in `servers.json`)

### Fixed
- Composer installation order in `make setup` (now runs after config prompts)
- `manage-users.php` permission errors now show resolution steps
- Guzzle downgraded from 8.0.x-dev to ^7.0 for stability
- Array access on potentially undefined server config keys in `ServerRenderer.php`

## [0.2.1] - 2026-01-03

### Breaking Changes
- None

### Added
- CLI security checks for `manage-users.php` (user authorization, SSH warnings, production confirmations)
- Audit logging for all user management operations in `logs/user-management-audit.log`
- `CLI_STRICT_USER_CHECK` and `CLI_ALLOWED_USERS` config options in `config.env`
- CLI mode support in `Auth` class to bypass session/role checks
- Server entry filtering: skip `servers.json` entries with keys beginning with underscore

### Fixed
- Composer installation order in `make setup` (now runs after config prompts)
- Fallback handling for composer detection in setup
- `manage-users.php` permission errors now show helpful resolution steps
- Renamed `make dev` to `make dev-start` and `make prod` to `make prod-start` for consistency
- Updated webserver config paths from `examples/` to `docs/examples/`
- Renamed `db.config.example` to `db.config.env.example` for consistency
- Removed database config section from `config.env.example` (moved to `db.config.env`)
- Array access on potentially undefined server config keys in `ServerRenderer.php`

## [0.2.0] - 2026-01-03

### Breaking Changes
- None

### Added  
- `EnvConfigManager` class for orchestrating configuration initialization workflow
- `EnvConfigCreator` class for automatic config file creation from templates
- `EnvConfigFatalException` for configuration errors with styled HTML error pages
- `ConfigInitializationResult` DTO for initialization results
- Interactive webserver configuration selection in `make setup`
- Example webserver configs for Apache, Nginx, Caddy in `docs/examples/webserver-configs/`
- Automatic config file creation from templates on first run
- Docker config file initialization in entrypoint

### Fixed
- Improved error handling in configuration initialization with user-friendly error pages
- Docker Apache vhost now uses server-level URL rewriting (removed `.htaccess` dependency)
- `.htaccess` files removed from root and config directories (security improvement)
- `make setup` now creates config files and guides webserver selection
- `make docker-setup` creates all required config files from examples
- `.env.example` clarified to separate Docker infrastructure vs application settings
- Config file permissions set to 600 (owner read/write only) on creation
- README streamlined to focus on quick start with links to detailed guides

## [0.1.0] - 2026-01-02

### Breaking Changes
- None

### Added  
- `EnvConfigManager` class for orchestrating configuration initialization workflow
- `EnvConfigCreator` class for automatic config file creation from templates
- `EnvConfigFatalException` for configuration errors with styled HTML error pages
- `ConfigInitializationResult` DTO for initialization results

### Fixed
- Improved error handling in configuration initialization with user-friendly error pages
- Automatic creation of missing config files from templates on first run


## [0.0.1] - 2025-08-22

### Breaking Changes

### Added

### Changed

### Fixed
