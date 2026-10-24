# Development setup

MATA supports native development and Docker development. The guided setup saves
the selected mode and environment in the ignored `.mata.mk` file.

## Requirements

!!! info ""
    For native development:

    - PHP 8.3 or newer
    - Composer
    - Git
    - `make`
    - MySQL or MariaDB for database-backed tests and cron work
    - OPA, installed with the project target or provided by a local service
    - Node.js and npm when rebuilding frontend assets

    Docker development needs Docker, Docker Compose, Git, and `make`. The
    Docker stack supplies PHP, MySQL, and OPA.

Documentation work also needs MkDocs; see [Documentation](documentation.md).

## Dashboard setup

```bash
git clone https://github.com/mata-sh/mata-dashboard.git
cd mata-dashboard
make setup
```

Choose native or Docker, then `dev`, `demo`, or `prod`. Development setup
creates missing files from `config.example/`. It does not install static default
credentials. Create the first user when setup offers it, or run:

```bash
make mata ARGS="user create"
```

Use `MODE=... ENV=...` to override the saved selection for one command:

```bash
MODE=native ENV=dev make test
MODE=docker ENV=dev make dev-start
```

For Docker, the dashboard is published on `WEB_PORT` and defaults to
`http://localhost:3300`.

!!! info "Port convention"
    MATA uses decade-spaced ports from 3300.

    | Service | Development port | Set by |
    | --- | --- | --- |
    | Dashboard | `3300` | `WEB_PORT` |
    | MATA Node | `3310` | node project |
    | OPA | `127.0.0.1:3320` | `OPA_PORT` |
    | MkDocs (`make docs-serve`) | `3330` | `Makefile` |
    | MySQL | `127.0.0.1:3306` | `MYSQL_PORT` |

    OPA and MySQL bind to loopback and are published by the development
    override only. Production publishes the dashboard port by default.

## Node setup

The dashboard needs a running MATA Node to collect real data. Clone the node
separately and follow its
[development instructions](https://github.com/mata-sh/mata-node). Configure the
same `DASHBOARD_SERVER_ID` and shared API key on both projects.

The `demo` environment uses the dashboard's fixed demo event fixture. It does
not require a node for those events.

## Common commands

```bash
make help
make status
make logs
make test
make opa-test
make opa-validate
make docs-serve
```

Native development with Composer dev dependencies can run all PHP checks with
`composer check`. In Docker, enter the development container with `make shell`
and run `composer check` there. `make test`, database commands, and user commands
use the saved mode and environment. Production writes and restores require
confirmation.

Rebuild Bulma after Sass changes with `npm install` and `npm run build-bulma`.

!!! info "Frontend assets"
    Frontend CI runs `npm audit` and builds `src/main.scss` directly with Sass
    into `public/assets/css/bulma.css`. There is no PostCSS step.

## Code layout

- `src/Controllers/`: HTTP coordination
- `src/Services/<Domain>/`: application and monitoring behavior, including view builders
- `src/Models/<Domain>/`: repositories and persistence
- `src/Database/`: database connection and migration runner
- `src/Config/`: environment and JSON configuration
- `src/Views/`: renderers
- `src/Cli/`: `bin/mata` commands
- `cron/`: scheduled jobs
- `policy/`: OPA policies and tests
- `migrations/`: Phinx migrations
- `src/Tests/`: PHPUnit tests
