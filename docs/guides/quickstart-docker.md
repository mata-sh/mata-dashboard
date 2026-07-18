# Quickstart Docker

!!! info "Requirements:" 
    Besides **PHP 8.3+**, **composer** and **git**, you need **make** and **Docker** + **Docker Compose**.

### 1. Start the Node Containers (Docker, Production)

```bash
git clone https://github.com/mata-sh/mata-node.git && cd mata-node

# Create config files and install composer deps
make setup

# Configure the node, then start it using the Docker instructions in the
# mata-node repository. Its Make targets are separate from this dashboard.
```

Edit `config/servers.json` to add your server:

```json
{
  "production-web-server": {
    "title": "Server Title",
    "url": "https://your-server.com/",
    "api_url": "https://your-server.com/mata-node/api",
    "api_key": "your_secure_api_key_here_32_characters",
    "primary_color": "#28a745",
    "secondary_color": "#ffffff",
    "info": "Web Server - Main Application",
    "check_disk_space": "on"
  }
}
```

The entry key (`production-web-server` above) must exactly match `DASHBOARD_SERVER_ID` in the
node's `config/config.env`, and `api_key` must match its `DASHBOARD_API_KEY`.

**Generating Api-Keys**

```bash
openssl rand -hex 32
```

### 2. Start the Dashboard Containers (Docker, Production)

```bash
git clone https://github.com/mata-sh/mata-dashboard.git && cd mata-dashboard

# Select Docker mode and production when prompted. Setup saves MODE and ENV
# in ignored .mata.mk and offers startup and initial-user creation.
# prod-start performs production validation. OPA is part of the Docker stack.
make setup

# On a first deployment, prepare bind-mount permissions. Run this again only
# after a host-side permission problem.
make prod-fix-perms

# If setup did not start services, Compose waits for healthy MySQL before
# starting mata-web; prod-start then runs migrations.
# It rejects APP_ENV mismatches and placeholder database passwords.
make prod-start
```

Access the dashboard at `http://<your-server-host>:3300/` (or the `WEB_PORT` in `.env`).

!!! tip "Port Convention:"
    In production, only the dashboard port is published by default: 3300 (`WEB_PORT`). OPA (3320) and MySQL (3306) host ports are dev-only localhost bindings.


### 3. Log In to the Dashboard

1. Navigate to `http://<your-server-host>:3300/`

!!! warning "User Management:"
    No default credentials are installed. Use the administrator created by `make setup`, or run `make users-create`. Production user commands run in a one-off admin container because `mata-web` mounts `config/` read-only.

2. Log in with the administrator credentials you created.
3. View your server dashboard

## Next Steps

- [Deployment Guide](deployment.md) for production setup.
- [Configuration Guide](configuration.md) - Customize your monitoring setup
<!-- - [Monitoring Guide](monitoring.md) - Learn about monitoring features -->

For troubleshooting help, see the [Troubleshooting Guide](../tutorials/troubleshooting.md).
