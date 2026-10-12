# Scripts

Application operations (users, servers, config, migrations, status) are
`bin/mata` commands; `php bin/mata` lists them. The Makefile covers setup, Docker,
logs, and backups, and `make mata ARGS="…"` runs `bin/mata` as the right account.
Run `make help` for the mode-aware command list.

```text
scripts/
├── database/
│   ├── hash-password.php          # prompts for a password, prints its hash (make db-hash-password)
│   └── mysql-operation.php        # backup, restore, shell (make db-backup|db-restore|db-shell)
├── docs/
│   └── sync-node-docs.sh          # copies mata-node pages into docs/node/ (make docs-serve|docs-deploy)
├── systemd/                       # native service units; opa.service is installed by make opa-install-service
├── cron_database_probe.php        # database readiness probe used by the Docker cron smoke check
├── server_metrics_diagnostic.php  # metrics row counts per server
└── setup.sh                       # guided setup (make setup)
```

Run scripts from the project root. In Docker, run them inside `mata-web`.
