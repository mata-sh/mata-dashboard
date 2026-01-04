
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
