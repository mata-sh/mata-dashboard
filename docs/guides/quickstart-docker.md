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

# Create config files and install composer deps
make setup

 # Start the production Docker stack
make prod-start     
```

Access the dashboard at `http://<your-server-host>:3300/` (or the `WEB_PORT` in `.env`).

!!! tip "Port Convention:"
    In production, only the dashboard port is published by default: 3300 (`WEB_PORT`). OPA (3320) and MySQL (3306) host ports are dev-only localhost bindings.


### 3. Log In to the Dashboard

1. Navigate to `http://<your-server-host>:3300/`

!!! warning "User Management:"
    These default users are created automatically. Change them after first login.

2. Login with these credentials:
   - **Username:** `mata` | **Password:** `mata` (admin role)
3. View your server dashboard

## Next Steps

- [Deployment Guide](deployment.md) for production setup.
- [Configuration Guide](configuration.md) - Customize your monitoring setup
<!-- - [Monitoring Guide](monitoring.md) - Learn about monitoring features -->

For troubleshooting help, see the [Troubleshooting Guide](../tutorials/troubleshooting.md).
