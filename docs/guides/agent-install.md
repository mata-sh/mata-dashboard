# Install with a coding agent

This page is a runbook for coding agents such as Claude Code or Codex. A person
can follow it too. Every command runs without prompts and reports through its
exit code, so each step can be checked before the next.

!!! info "Rules for the agent"
    - Never run `sudo`. When a step needs it, stop and give the person the exact
      command, then continue after they confirm.
    - Show generated passwords to the person once. Never write them to a file,
      a commit, or a log.
    - Never commit `config/*.env` or `config/servers.secrets.json`.
    - Stop and ask when a check fails twice; do not edit application code to make
      a check pass.

## 1. Choose the path

Use Docker unless the person asks for a native install. Docker brings MySQL and
OPA along, so an agent can finish it alone. A native install needs a database,
a web server, a crontab, and sudo, which only the person can set up.

```bash
docker info >/dev/null && docker compose version
```

If `docker info` reports `permission denied`, the account may not use Docker.
Stop and let the person choose between adding it to the `docker` group, which
grants root-equivalent access, and running the install as root.

## 2. Install (Docker)

Check out the latest release and run setup:

```bash
tag=$(git ls-remote --tags --refs --sort=-v:refname https://github.com/mata-sh/mata-dashboard.git | head -n1 | sed 's|.*refs/tags/||')
git clone --depth 1 --branch "$tag" https://github.com/mata-sh/mata-dashboard.git
cd mata-dashboard
NONINTERACTIVE=1 make setup MODE=docker ENV=prod START_SERVICES=yes ADMIN_USER=admin
```

Setup generates the database passwords, builds and starts the stack, runs the
migrations, and creates the `admin` account. It prints that account's password
once: pass it to the person.
Run it again after a failure; setup keeps existing files and values.

`NONINTERACTIVE=1` matters even without a visible terminal: some agents run
commands in a pseudo-terminal, where setup would otherwise wait for answers.
All variables are listed under [Unattended setup](quickstart-docker.md#unattended-setup).

## 3. Verify

```bash
curl -fsS http://127.0.0.1:3300/health    # {"status":"healthy",...}
make mata ARGS="config validate --json"
make doctor
make mata ARGS="status --json"
```

Right after an install, two results are expected:

- `config validate` and `make doctor` exit `1` with the warning
  `Server configuration is empty` until the first server is added.
- `make doctor` warns `servers-data-fetch: never` until the container's cron
  runs that job for the first time, within five minutes. Wait and repeat it.

`status` exits `0` once that first run has happened. Jobs that run daily or
every six hours show `"state": "pending"` until their first run, which is fine.
Any other warning or error is a real problem.

## 4. Hand over to the person

The dashboard listens on `127.0.0.1:3300` only. Tell the person how to reach it,
and stop here for anything outside the repository:

- **From their machine:** `ssh -L 3300:127.0.0.1:3300 <user>@<host>`, then open
  `http://localhost:3300` and sign in as `admin`.
- **On a domain:** an HTTPS reverse proxy in front of port 3300 needs DNS and a
  certificate, which the person sets up. See
  [Docker quickstart](quickstart-docker.md#production) for the bind address.

## 5. Add a server

Each monitored machine runs [MATA Node](https://github.com/mata-sh/mata-node).
Installing it is a separate task on that machine; do it only with access there.
On the dashboard, add the node and let the CLI generate its key:

```bash
make mata ARGS="server add web-01 --api-url https://node.example.com/ --json"
```

The name must equal the node's `DASHBOARD_SERVER_ID`. The command prints the
`DASHBOARD_SERVER_ID` and `DASHBOARD_API_KEY` lines for the node's
`config/config.env` and exits `1` until the node has them. Once the node is
configured:

```bash
make mata ARGS="server test web-01"   # exit 0: health and a signed request pass
```

The details are in [Add your first server](first-server.md).

## Native install

Only when the person asks for it. The agent can prepare the checkout:

```bash
NONINTERACTIVE=1 make setup MODE=local ENV=prod WEBSERVER=none \
  MATA_USERS_FILE=/var/lib/mata/users.json
```

Then stop and give the person this list, from the [quick start](quickstart.md):

1. Create the MySQL database and user, and set them in `config/db.config.env`.
2. Create the users file directory if setup could not, with the `sudo install`
   command it printed.
3. Serve `public/` with a web server; examples are in
   `docs/examples/webserver-configs/`.
4. Install the crontab from `cron/crontab.shared-hosting.template`.
5. Optionally `make opa-install-service`, or set `ALARM_DECISION_DRIVER=builtin`.

Afterwards, run `make db-migrate ALLOW_PRODUCTION=CONFIRM_PRODUCTION`,
`make mata ARGS="user create admin admin --generate-password --yes"`, and the
checks in step 3.

## After the install

To change the configuration later, copy `config.example/AGENTS.md` to
`config/AGENTS.md` and follow it: validate every edit with
`make mata ARGS="config validate"`, check with `status`, and explain alarms
with `alarm explain`.

To remove a Docker install, stop the stack, then delete the files the
containers own (the database in `data/`, `logs/`, `temp/`) through a container,
since your account cannot:

```bash
make clean MODE=docker ENV=prod ALLOW_DELETE="DELETE prod"
docker run --rm -v "$PWD:/mata" php:8.3-apache-trixie rm -rf /mata/data /mata/logs /mata/temp
cd .. && rm -rf mata-dashboard
```
