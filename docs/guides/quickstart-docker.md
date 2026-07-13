# Quickstart Docker

!!! info "Requirements:" 
    Besides **PHP 8.3+**, **composer** and **git**, you need **make** and **Docker** + **Docker Compose**.

### 1. Start the Node Containers (Docker, Production)

```bash
git clone https://github.com/mata-sh/mata-node.git && cd mata-node

# Create config files and install composer deps
make setup

# Start Docker stack
make docker-setup
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
**Generating Api-Keys**

```bash
openssl rand -hex 32
```

### 2. Start the Dashboard Containers (Docker, Production)

```bash
git clone https://github.com/mata-sh/mata-dashboard.git && cd mata-dashboard

# Select Docker and production when prompted. Setup configures the stack,
# offers to start its services (including OPA), runs migrations optionally,
# and offers to create the initial administrator.
make setup

# If you did not start services in the setup wizard:
make prod-start     
```

Access the dashboard at `http://<your-server-host>:3300/` (or the `WEB_PORT` in `.env`).

!!! tip "Port Convention:"
    In production, only the dashboard port is published by default: 3300 (`WEB_PORT`). OPA (3320) and MySQL (3306) host ports are dev-only localhost bindings.


### 3. Log In to the Dashboard

1. Navigate to `http://<your-server-host>:3300/`

!!! warning "User Management:"
    No default credentials are installed. Use the administrator created by `make setup`, or run `make users-create`.

2. Log in with the administrator credentials you created.
3. View your server dashboard

## Next Steps

- [Deployment Guide](deployment.md) for production setup.
- [Configuration Guide](configuration.md) - Customize your monitoring setup
<!-- - [Monitoring Guide](monitoring.md) - Learn about monitoring features -->

For troubleshooting help, see the [Troubleshooting Guide](../tutorials/troubleshooting.md).
