
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

# Create config files and install composer deps
make setup

# Start the full Docker dev stack (runs setup if needed)
make dev-start
```

**Access the dashboard at `http://localhost:3300/` (or the `WEB_PORT` in `.env`).**

!!! tip "Port Convention:"
    MATA uses decade-spaced ports - Dashboard: 3300, Node: 3310, OPA: 3320, MkDocs: 3330, MySQL: 3306


### 3. Log In to the Dashboard

1. Navigate to `http://localhost:3300/`
2. Login with these credentials:
   - **Username:** `mata` | **Password:** `mata` (admin role)
3. View your server dashboard

!!! info "User Management:"
   These default users are created automatically. For manual setup, you'll need to configure `config/users.json` or create users with `make users-create`.
