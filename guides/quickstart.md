# Quick start

This guide starts a native or shared-hosting dashboard. For Docker, use the
[Docker quickstart](quickstart-docker.md).

## Requirements

!!! info ""
    - PHP 8.3 or newer, Composer, Git, and `make`
    - MySQL or MariaDB for migrations, event history, alarms, and historical metrics
    - A web server serving `public/`
    - Cron or an equivalent scheduler

    The dashboard renders basic pages without a database, but full monitoring
    requires one.

## 1. Run setup

`curl -fsSL https://mata.sh/install.sh | sh -s -- --native` clones the newest
release into `./mata-dashboard`, runs the unattended setup with production
defaults, and prints the steps below that are left to you. It never runs
`sudo`. The manual way:

```bash
git clone https://github.com/mata-sh/mata-dashboard.git
cd mata-dashboard
make setup
```

Answer no to the Docker question and choose the environment. Setup creates the core
configuration files, installs Composer dependencies, and can create the first
user. To run it without prompts, set the answers as variables; see
[Unattended setup](quickstart-docker.md#unattended-setup):

```bash
NONINTERACTIVE=1 make setup MODE=native ENV=prod WEBSERVER=nginx ADMIN_USER=admin \
  MATA_USERS_FILE=/var/lib/mata/users.json
```

## 2. Configure services

Set database credentials in `config/db.config.env`, then run migrations:

```bash
make db-migrate
```

For alarm decisions, choose one path in `config/config.env`:

```dotenv
# Native OPA service
ALARM_DECISION_DRIVER=opa
OPA_ALARM_BASE_URI=http://127.0.0.1:3320
```

`make setup` offers to install OPA. Run these to install it later, or after
declining those prompts:

```bash
make setup-opa            # downloads and checksum-verifies bin/opa
make opa-install-service  # installs the systemd unit and starts it (needs sudo)
make opa-status
```

The unit serves the repository `policy/` directory on `127.0.0.1:3320`, which
is the address `OPA_ALARM_BASE_URI` expects above.

Hosts that cannot run OPA use the built-in driver instead:

```dotenv
# Shared hosting or another host that cannot run OPA
ALARM_DECISION_DRIVER=builtin
```

!!! warning "The built-in driver is intentionally limited"
    It allows critical events and `fatal_errors_occurring` on the channels
    their notification rule selects, and denies every other candidate. Use the
    OPA driver wherever it can run.

Configure `config/mail.config.env` before enabling alarm email.

## 3. Serve and schedule the dashboard

Point the web server at `public/`. For shared hosting, install the
[`cron/crontab.shared-hosting.template`](https://github.com/mata-sh/mata-dashboard/blob/main/cron/crontab.shared-hosting.template)
after replacing `APP_ROOT` and `PHP_BIN`.

The scheduled jobs collect node data, persist metrics, evaluate alarms, dispatch
email, and clean retained data. Native installations need an equivalent cron
configuration; the same template is a usable starting point. The job list is in
[Add your first server](first-server.md).

The jobs write to `logs/` and `logs/cron/`. Rotate them with
[`cron/logrotate.shared-hosting.conf.template`](https://github.com/mata-sh/mata-dashboard/blob/main/cron/logrotate.shared-hosting.conf.template)
after replacing the placeholder application path; logrotate does not expand
`APP_ROOT`. Install it beside the crontab for a user-owned setup, or as
`/etc/logrotate.d/mata-dashboard` when you have root.

## 4. Connect a node

Install MATA Node separately on the monitored host, then follow
[Add your first server](first-server.md). The dashboard pulls from the node; the
node never calls back.

## 5. Verify

If you declined the user prompt during setup, create one now:

```bash
make mata ARGS="user create"
```

Open the dashboard, sign in, and run:

```bash
make doctor
```

A node may appear before historical chart rows exist. Wait for a successful
metrics collection cycle.

## Next steps

- [Configuration](configuration.md)
- [Monitoring](monitoring.md)
- [User management CLI](user-management.md)
- [Secure an installation](secure-application.md)
