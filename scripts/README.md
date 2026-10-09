# Scripts

The Makefile is the preferred interface for setup and operational commands.
Run `make help` for the mode-aware command list.

## Layout

```text
scripts/
├── database/           # migrations, backups, integrity checks, passwords
├── user-management/   # dashboard user CLI
├── docs/               # documentation tooling
├── systemd/            # native service units
├── cron_database_probe.php
├── server_metrics_diagnostic.php
└── setup.sh            # guided setup
```

## Common commands

```bash
make setup
make status
make logs
make users-list
make db-status
make test
make docs-serve
```

Use `MODE=local|docker ENV=dev|demo|prod` to override the selection saved by
`make setup`.

## Direct scripts

These examples are for a native checkout with PHP, Composer dependencies, and
configuration available on the host. In Docker, prefer the matching Make target
or run the PHP command inside `mata-web`.

Run scripts from the project root:

```bash
php scripts/database/migrate.php status --env=dev
php scripts/database/hash-password.php "mypassword"
php scripts/user-management/manage-users.php list --env=dev
php scripts/server_metrics_diagnostic.php
```

User-management commands must run as the PHP-FPM user when that user owns the
authentication data. Prefer Make targets for database writes so environment and
production confirmation checks apply.

