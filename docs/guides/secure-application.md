# Secure an installation

Use HTTPS for the dashboard and every production node. Point each web server at
the application's `public/` directory. Do not expose the repository root.

## Configuration

Keep credentials in the ignored configuration files and set restrictive file
permissions. The production Docker setup mounts `config/` read-only.

Recommended production values in `config/config.env`:

```dotenv
APP_ENV=prod
DISPLAY_ERRORS=0
SECURITY_HEADERS_ENABLED=ON
TRUSTED_PROXIES=203.0.113.10
HSTS_ENABLED=OFF
RATE_LIMIT_ENABLED=ON
RATE_LIMIT_STORAGE=file
CLI_STRICT_USER_CHECK=ON
```

With `CLI_STRICT_USER_CHECK=ON`, only the operating-system users in
`CLI_ALLOWED_USERS` may run `bin/mata user` commands. Login, dashboard, and API
routes have separate limits, `RATE_LIMIT_<GROUP>_MAX_REQUESTS` and
`RATE_LIMIT_<GROUP>_WINDOW_SECONDS`.

Put database credentials in `config/db.config.env` and mail credentials in
`config/mail.config.env`. Use unique values for every installation and node.
Never put real secrets in `config.example/`, documentation, or a Docker image
layer.

## Node credentials

Use a unique secret for each node; `bin/mata server add` generates one. It
lives in `config/servers.secrets.json` on the dashboard and as
`DASHBOARD_API_KEY` on the node. Keep `servers.secrets.json` out of version
control.

The dashboard signs requests with `X-Mata-Auth` and never sends the secret
itself. Rotate a node secret on both sides if it is exposed.

## Network controls

- Use HTTPS node URLs. `allow_http` is for local development only.
- Restrict node access to the dashboard host or trusted network where possible.
- Enable the dashboard IP allowlist with `IP_WHITELIST_ENABLED=ON` when its
  allowed addresses are stable.
- Set `TRUSTED_PROXIES` to the exact proxy IPs or CIDR ranges before relying
  on `X-Forwarded-Proto`.
- Enable `HSTS_ENABLED=ON` only when HTTPS is permanent for the domain and all
  subdomains.
- Keep OPA and MySQL on the internal Docker network. The development Compose
  file binds their host ports to loopback only; production does not publish them.

!!! warning "Docker bypasses host firewalls"
    Docker publishes ports through its own iptables rules, which take effect
    before ufw or firewalld rules. The dashboard port binds to `127.0.0.1` by
    default (`WEB_BIND` in `.env`). With `WEB_BIND=0.0.0.0`, anyone who can reach
    the host can bypass the HTTPS proxy, so restrict that port at the network or
    cloud firewall.

## Authentication data

Set `MATA_USERS_FILE` to an absolute path outside the deployment and web root:

```dotenv
MATA_USERS_FILE=/var/lib/mata-dashboard/users.json
```

Give its directory mode `0700` and PHP-FPM ownership. The application creates
the users file and lock file with mode `0600`. Manage accounts with the
[user management CLI](user-management.md), not by editing JSON by hand.

Docker stores users on the `mata_users` named volume. Back up that volume as
part of the deployment's credential recovery plan.

## Web server boundary

Only `public/` should be web-accessible. Deny access to `config/`, `src/`,
`vendor/`, `temp/`, and `logs/`. Use the examples in
[`docs/examples/webserver-configs`](../examples/webserver-configs/README.md) as
starting points and review them before production use.

The dashboard's `/health` endpoint is public for service checks. All
`/watch/*` and `/api/*` routes require a session. Unsafe requests require CSRF
validation.

## Validate the deployment

```bash
make doctor MODE=docker ENV=prod
make mata MODE=docker ENV=prod ARGS="db status"
make mata MODE=docker ENV=prod ARGS="user list"
```

For native installs, run the same checks with `MODE=native`. Review application,
cron, PHP, and web-server logs after the first collection and alarm cycles.
