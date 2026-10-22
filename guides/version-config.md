# Version your config

`config/` belongs to your installation. The application repository ignores it
completely, so you can keep it in its own Git repository. You get history,
diffs, and rollback for thresholds, servers, and alarm rules, and an agent can
propose configuration changes as commits you review.

Credentials stay out of that repository. They live only in `*.env` files and in
`servers.secrets.json`, which the repository ignores.

## What to version

| Version | Keep out of Git |
| --- | --- |
| `servers.json`, `thresholds.json`, `intervals.json`, `ttl.json`, `data_retention.json` | `config.env`, `db.config.env`, `mail.config.env`: database password, mailer DSN, webhook URLs, per-host settings |
| `notification_rules.json`, `channels.json`, `limits.json` | `servers.secrets.json`: node API keys |
| `probes.json`, `menu.json`, `charts.json` | `users.json`, `users.json.lock`: account state, not configuration |
| | `demo/`: local demo data |

Use this `.gitignore` in the configuration repository:

```gitignore
# Credentials and per-host settings
*.env
servers.secrets.json
# Account state (development fallback location)
users.json
users.json.lock
# Local demo data
demo/
```

!!! danger "Committed credentials"
    Deleting a committed secret in a later commit leaves it in the history and
    on every clone. If `servers.secrets.json` or an `.env` file was pushed,
    rotate what it contains: generate a new node key (`DASHBOARD_API_KEY` on the
    node and `api_key` in `servers.secrets.json`) and change the database and
    mail passwords.

## Option 1: a repository in `config/`

Turn the existing directory into a repository:

```bash
cd config
git init -b main
# create .gitignore with the content above
git add .
git commit -m "Dashboard configuration"
```

To keep a copy off the host, push it to a private remote:

```bash
git remote add origin git@github.com:<you>/mata-config.git
git push -u origin main
```

## Option 2: clone an existing configuration

On a new host, clone the configuration before running setup:

```bash
git clone https://github.com/mata-sh/mata-dashboard.git
cd mata-dashboard
git clone git@github.com:<you>/mata-config.git config
make setup
```

Setup only creates missing files. It adds the `.env` files and
`servers.secrets.json` from `config.example/`; fill them in as described in
[Configuration](configuration.md).

If setup already created `config/`, Git cannot clone into it. Move the
generated directory aside and copy the local-only files back:

```bash
mv config config.setup
git clone git@github.com:<you>/mata-config.git config
cp -p config.setup/*.env config.setup/servers.secrets.json config/
```

## Option 3: keep the repository elsewhere

Clone the configuration anywhere and point `config` at it with a symlink:

```bash
git clone git@github.com:<you>/mata-config.git /srv/mata-config
ln -s /srv/mata-config config
```

Native installations follow the symlink. Docker Compose resolves it on the host
when it mounts `config/`, so no Compose changes are needed.

## Permissions

The dashboard only reads `config/`. Keep the repository owned by the account
that runs Git, and give the web server read access through its group. In Docker
production, `make prod-fix-perms` does this: it keeps the owner, sets the group
to `www-data`, and uses `0750` for directories and `0640` for files.

!!! warning "Dubious ownership"
    Git refuses to work in a repository owned by another user and reports
    `detected dubious ownership`. Development containers before this release
    handed `config/` to `www-data` on every start. Give it back to your account
    once with `sudo chown -R "$(id -un)" config/`.

## Check the setup

```bash
make doctor
```

`doctor` shows where `config` points when it is a symlink, whether it is a Git
repository and how many changes are uncommitted, and whether
`servers.secrets.json` exists. It warns when `servers.json` still contains
`api_key`.

!!! info "Policies are part of the application"
    The Rego policies in `policy/` ship with the dashboard and are not part of
    your configuration. Adjust alarm behaviour through `thresholds.json`,
    `notification_rules.json`, and `intervals.json`.

## Why not a Git submodule

A submodule would record your private repository's URL and commit inside the
application repository, which you update from upstream. The dashboard never
needs to pin a configuration version, so the options above work without that
coupling.
