# Quick Start

Get MATA Dashboard up and running in minutes with this quick start guide.

## Prerequisites

Make sure you have completed the [Installation Guide](installation.md) before proceeding.

## Quick Setup

### 1. Start the Development Server

For quick testing and development:

```bash
cd /path/to/mata-dashboard
php -S 0.0.0.0:3300 -t public/
```

Access the dashboard at `http://localhost:3300/`

> **Port Convention**: MATA uses decade-spaced ports - Dashboard: 3300, Node: 3310, OPA: 3320, MkDocs: 3330, MySQL: 3306

### 2. Configure Your First Server

Edit `config/servers.json` to add your first monitored server:

```json
{
  "production-web-server": {
    "title": "Production Server",
    "url": "https://your-production-server.com/",
    "api_url": "https://your-production-server.com/mata-node/api",
    "api_key": "your_secure_api_key_here_32_characters",
    "primary_color": "#28a745",
    "secondary_color": "#ffffff",
    "info": "Production Web Server - Main Application",
    "check_disk_space": "on"
  }
}
```

!!! tip "Generating Secure API Keys"
    Generate a secure API key using:
    ```bash
    openssl rand -hex 32
    ```

### 3. Login to Dashboard

1. Navigate to `http://localhost:3300/`
2. Login with one of these credentials:
   - **Username:** `dev` | **Password:** `password` (admin role)
   - **Username:** `admin` | **Password:** `password` (admin role)
3. View your server dashboard

> **Note:** For Docker development (`make dev-start`), these default users are created automatically. For manual setup, you'll need to configure `config/users.json` or create users with `make users-create`.

## Setting Up Automated Monitoring

### Configure Cron Jobs

For automated data collection, set up these cron jobs:

```bash
# Collect server metrics every 5 minutes
*/5 * * * * /usr/bin/php /path/to/mata-dashboard/cron/servers_metrics_collect.php

# Aggregate server metrics hourly (runs at minute 5 of each hour)
5 * * * * /usr/bin/php /path/to/mata-dashboard/cron/servers_metrics_aggregate.php
```

See the [Deployment Guide](deployment.md) for production cron setup.

## Verifying Your Setup

### Check Server Connectivity

1. Navigate to the main dashboard
2. Your server should appear with a status indicator
3. Green = Connected, Red = Connection issues

### Test Alerting

1. Navigate to **Settings** > **Thresholds**
2. Lower a threshold temporarily (e.g., disk space to 1%)
3. Wait for the next cron run
4. Check that you receive an email alert

## Next Steps

- [Configuration Guide](configuration.md) - Customize your monitoring setup
- [Monitoring Guide](monitoring.md) - Learn about monitoring features
- [Adding Your First Server Tutorial](../tutorials/first-server.md) - Detailed walkthrough

## Common Issues

### Server Shows as Disconnected

- Verify API endpoint is accessible from the dashboard
- Check API key matches between dashboard and node
- Review `temp/logs/` for connection errors

### No Email Alerts

- Verify SMTP settings in `config/mail.config.env`
- Check spam/junk folders
- Test email sending manually

For more troubleshooting help, see the [Troubleshooting Guide](../tutorials/troubleshooting.md).
