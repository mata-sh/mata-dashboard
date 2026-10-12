# Scripts

Application operations (users, servers, config, migrations, status) are
`bin/mata` commands; `php bin/mata` lists them. The Makefile covers setup, Docker,
logs, and backups, and `make mata ARGS="…"` runs `bin/mata` as the right account.
Run `make help` for the mode-aware command list.

## Layout

```text
scripts/
├── database/           # backups, restore, database shell, password hashing
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
make mata ARGS="user list"
make test
make docs-serve
```

Use `MODE=local|docker ENV=dev|demo|prod` to override the selection saved by
`make setup`.

## Direct scripts

These examples are for a native checkout with PHP, Composer dependencies, and
configuration available on the host. In Docker, run them inside `mata-web`.

Run scripts from the project root:

```bash
php scripts/database/hash-password.php "mypassword"
php scripts/server_metrics_diagnostic.php
```

