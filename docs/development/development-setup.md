
### Prerequisites

!!! info "Requirements:" 
    Besides **PHP 8.3+**, **composer** and **git**, you need **Docker** + **Docker Compose** and **make**.

### 1. Start the Node Containers (Docker, Dev)

```bash
git clone https://github.com/mata-sh/mata-node.git && cd mata-node

# Create config files and install composer deps
make setup

# Start the full Docker dev stack (runs setup if needed)
make dev-start
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


### 2. Start the Dashboard Containers (Docker, Dev)

```bash
git clone https://github.com/mata-sh/mata-dashboard.git && cd mata-dashboard

# Select Docker mode and dev or demo when prompted. Setup saves MODE and ENV
# in ignored .mata.mk, runs dev-setup validation, and can create the initial user.
make setup

# Run this only if startup was skipped in the wizard. Compose waits for healthy
# MySQL before starting mata-web; migrations then run through mata-web.
# OPA is part of the Docker stack.
make dev-start
```

**Access the dashboard at `http://localhost:3300/` (or the `WEB_PORT` in `.env`).**

!!! tip "Port Convention:"
    MATA uses decade-spaced ports - Dashboard: 3300, Node: 3310, OPA: 127.0.0.1:3320, MkDocs: 3330, MySQL: 127.0.0.1:3306 in dev.


### 3. Log In to the Dashboard

1. Navigate to `http://localhost:3300/`.
2. Log in with the user created by `make setup`.
3. View your server dashboard.

!!! info "User Management:"
   No static default credentials are installed. Create additional users with `make users-create`.
