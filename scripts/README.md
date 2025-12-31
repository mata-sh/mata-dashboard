# Scripts Directory

This directory contains utility scripts organized by purpose.

> **💡 Tip**: Most scripts have convenient Makefile targets. Run `make help` to see all available commands.

## Directory Structure

```
scripts/
├── database/           # Database management utilities
├── dev/               # Development and testing tools
├── build/             # Build and release automation
└── docs/              # Documentation tooling
```

## Quick Reference: Makefile Targets

Instead of running scripts directly, use Makefile targets for a better developer experience:

```bash
# Setup
make setup              # Full development setup
make docker-setup       # Docker environment setup

# Development
make dev                # Start development environment
make test               # Run tests
make shell              # Access container

# Database
make db-migrate         # Run migrations
make db-backup          # Backup database
make users-list         # List users

# See all commands
make help
```

## Database Scripts (`database/`)

Database management and migration utilities:

- **`migrate.php`** - Run database migrations
- **`database-integrity.php`** - Check database integrity and consistency
- **`migrate-password.php`** - Migrate password hashing to new format
- **`hash-password.php`** - Generate password hashes

Usage examples:
```bash
php scripts/database/migrate.php
php scripts/database/hash-password.php "mypassword"
```

## Development Scripts (`dev/`)

Development workflow and testing tools:

- **`setup.sh`** - Initialize development environment
- **`run-tests.php`** - Execute test suite
- **`manage-users.php`** - Manage user accounts (CLI)

Usage examples:
```bash
./scripts/dev/setup.sh
php scripts/dev/run-tests.php
php scripts/dev/manage-users.php list
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
./scripts/dev/setup.sh
php scripts/dev/run-tests.php

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
