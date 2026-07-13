# Scripts Directory

This directory contains utility scripts organized by purpose.

> **💡 Tip**: Most scripts have convenient Makefile targets. Run `make help` to see all available commands.

## Directory Structure

```
scripts/
├── database/           # Database management utilities
├── user-management/   # User management CLI
├── build/             # Build and release automation
└── docs/              # Documentation tooling
```

## Quick Reference: Makefile Targets

Instead of running scripts directly, use Makefile targets for a better developer experience:

```bash
# Setup: interactively save local/docker mode and dev/demo/prod environment in .mata.mk
make setup
# One-command override: MODE=docker ENV=dev make status

# Mode-aware commands
make users-list         # Also: users-create, users-unlock, users-delete, users-passwd
make opa-status         # Also: opa-start, opa-stop; all dispatch by selected mode
make test               # Also: test-coverage
make db-migrate         # Also: db-rollback, db-status, db-check, db-shell
make db-backup          # Timestamped backup in backups/ by default; FILE=... is optional
make db-restore FILE=backups/backup.sql  # FILE is required; production writes/restores confirm
# Local backup/restore/db-shell require mysqldump and mysql clients in PATH.
make status             # Status
make logs               # Also: logs-app, logs-php; all dispatch by selected mode

# Docker-only commands after Docker setup
make dev-start          # Dev/demo lifecycle; Compose gates mata-web on healthy MySQL
make dev-logs           # Start dev/demo stack and follow logs
make dev-stop           # Also: dev-restart, dev-recreate, dev-rebuild
make prod-fix-perms     # Initial provisioning or bind-mount permission recovery
make prod-start         # Validates APP_ENV and placeholder passwords before startup
make prod-stop          # Also: prod-restart, prod-rebuild
make logs-web           # mata-web logs
make logs-db            # mata-db logs
make ps                 # Container status
make shell              # mata-web shell
make shell-www          # mata-web shell as www-data
make docker-clean       # Project Docker cleanup
# docker-init is shared; dev-setup and prod-setup validate environment-specific prerequisites.
# docker-setup is deprecated; use make setup or the environment-specific setup target.

# See all commands
make help
```

## Database Scripts (`database/`)

Database management and migration utilities:

- **`migrate.php`** - Run database migrations
- **`database-integrity.php`** - Check database integrity and consistency
- **`hash-password.php`** - Generate password hashes

Usage examples:
```bash
php scripts/database/migrate.php
php scripts/database/hash-password.php "mypassword"
```

## Setup and User Management

- **`setup.sh`** - Guided native or Docker setup
- **`user-management/manage-users.php`** - Manage user accounts (CLI)

Usage examples:
```bash
./scripts/setup.sh
php scripts/user-management/manage-users.php list
```

## Build Scripts (`build/`)

Build and release automation:

- **`bootstrap.php`** - Bootstrap application dependencies
- **`generate-changelog.sh`** - Generate changelog from git history

Usage examples:
```bash
php scripts/build/bootstrap.php
./scripts/build/generate-changelog.sh
```

## Documentation Scripts (`docs/`)

Documentation generation and maintenance tools. See [docs/README.md](docs/README.md) for details.

- **`ai-update.sh`** - AI-powered documentation updater using Claude Code

Usage examples:
```bash
# Update docs based on code changes
./scripts/docs/ai-update.sh
```

## Quick Reference

### Running from Project Root

All scripts are designed to be run from the project root directory:

```bash
# Database
php scripts/database/migrate.php

# Development
./scripts/setup.sh

# Build
./scripts/build/generate-changelog.sh

# Documentation
./scripts/docs/ai-update.sh
```

### Using Make Targets

Many common tasks have Makefile shortcuts. See the main [Makefile](../Makefile) for available targets.

```bash
make docs-serve      # Serve documentation locally
make docs-deploy     # Deploy to GitHub Pages
```
