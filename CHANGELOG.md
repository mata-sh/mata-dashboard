# Changelog

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- The documentation site includes the MATA Node reference (configuration, Docker, custom log parsers), synced from mata-node at build time

### Changed
- Docker images build on the latest PHP 8.3 release on Debian 13 (`php:8.3-apache-trixie`) with the latest Composer 2, and `make dev-rebuild` / `make prod-rebuild` pull the newest base image. If your `.env` sets `PHP_VERSION=8.3.14`, change it to `8.3`; no Debian 13 image exists for 8.3.14
- Database cards read MATA Node's `/databases/overview` and show connection numbers once per database server; the process list opens per database or for the whole server and is fetched live. Nodes without database monitoring show the section as not configured. Requires MATA Node 0.3.0 or later

### Fixed
- Dashboard notices with a saved choice (such as "never" or "snooze") now close only after the server stores it; a failed request leaves the notice visible

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
