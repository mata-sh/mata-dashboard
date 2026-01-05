
## [1.3.0] - 2026-01-05

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

## [1.3.0] - 2026-01-03

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

## [1.2.0] - 2026-01-03

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

## [1.1.0] - 2026-01-02

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


## [1.0.0] - 2025-08-22

### Breaking Changes

### Added

### Changed

### Fixed

## Misc
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
