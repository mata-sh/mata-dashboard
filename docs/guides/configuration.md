# Configuration

This guide covers all configuration options for MATA Dashboard.

## Configuration Files Overview

MATA Dashboard uses several configuration files located in the `config/` directory:

| File | Purpose | Required |
|------|---------|----------|
| `config.env` | Main application settings | Yes |
| `mail.config.env` | Email/SMTP configuration | Yes |
| `servers.json` | Monitored servers definition | Yes |
| `users.json` | User authentication data | Yes |
| `thresholds.json` | Metric thresholds and alarm triggers | No (uses defaults) |
| `intervals.json` | Event/alarm cooldown intervals | No (uses defaults) |
| `ttl.json` | Cache TTL settings | No (uses defaults) |
| `data_retention.json` | Metrics retention settings | No (uses defaults) |
| `mysql.mata.env` | Alternative database config | No |

## Main Configuration (config.env)

The primary configuration file for application settings.

### Database Settings

```bash
# Database connection
DB_HOST=localhost
DB_PORT=3306
DB_NAME=mata_dashboard
DB_USER=mata_user
DB_PASS=secure_password_here
```

### Application Settings

```bash
# Application name and URL
APP_NAME="MATA Dashboard"
APP_URL=https://mata.yourdomain.com

# Debug mode (disable in production)
DEBUG_MODE=false

# Session settings
SESSION_NAME=mata_session
SESSION_LIFETIME=3600
```

### Security Settings

```bash
# Security secrets (generate with: openssl rand -hex 32)
APP_SECRET=your_64_character_secret_key_here

# Rate limiting
RATE_LIMIT_ENABLED=true
RATE_LIMIT_MAX_REQUESTS=60
RATE_LIMIT_WINDOW=60
```

## Mail Configuration (mail.config.env)

SMTP settings for email alerts and notifications.

```bash
# SMTP Server Settings
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_ENCRYPTION=tls
MAIL_USERNAME=your-email@gmail.com
MAIL_PASSWORD=your-app-password

# Email Addresses
MAIL_FROM_ADDRESS=alerts@yourdomain.com
MAIL_FROM_NAME="MATA Alerts"
MAIL_TO_ADDRESS=admin@yourdomain.com
```

!!! warning "Gmail Configuration"
    If using Gmail, you must use an [App Password](https://support.google.com/accounts/answer/185833), not your regular password.

## Server Configuration (servers.json)

Define the servers and applications to monitor.

### Server Definition

```json
{
  "server-identifier": {
    "title": "Display Name",
    "url": "https://server.example.com/",
    "api_url": "https://server.example.com/mata-node/api",
    "api_key": "32_character_secure_api_key",
    "primary_color": "#28a745",
    "secondary_color": "#ffffff",
    "info": "Server description",
    "check_disk_space": "on"
  }
}
```

### Configuration Fields

| Field | Description | Example |
|-------|-------------|---------|
| `server-identifier` | Unique server key (no spaces) | `production-web-01` |
| `title` | Display name in dashboard | `Production Web Server` |
| `url` | Base server URL | `https://example.com/` |
| `api_url` | Node API endpoint | `https://example.com/mata-node/api` |
| `api_key` | Authentication key (32+ chars) | Generate with `openssl rand -hex 32` |
| `primary_color` | Server card color | `#28a745` |
| `secondary_color` | Text color | `#ffffff` |
| `info` | Server description | `Production Web Server` |
| `check_disk_space` | Enable disk monitoring | `on` or `off` |

### Multiple Servers Example

```json
{
  "prod-web-01": {
    "title": "Production Web Server",
    "url": "https://web01.example.com/",
    "api_url": "https://web01.example.com/mata-node/api",
    "api_key": "api_key_for_web01",
    "primary_color": "#28a745",
    "secondary_color": "#ffffff",
    "info": "Main production web server",
    "check_disk_space": "on"
  },
  "prod-db-01": {
    "title": "Production Database Server",
    "url": "https://db01.example.com/",
    "api_url": "https://db01.example.com/mata-node/api",
    "api_key": "api_key_for_db01",
    "primary_color": "#007bff",
    "secondary_color": "#ffffff",
    "info": "Primary database server",
    "check_disk_space": "on"
  }
}
```

## User Configuration (users.json)

User accounts for dashboard access.

### User Definition

```json
{
  "admin": {
    "username": "admin",
    "password": "$2y$10$hashed_password_here",
    "email": "admin@example.com",
    "role": "admin"
  }
}
```

### Generating Password Hashes

Use the provided script:

```bash
php scripts/hash-password.php
```

Follow the prompts to enter a password and receive the bcrypt hash.

## Threshold Configuration (thresholds.json)

Configure alert thresholds for different metrics.

### Threshold Levels

- **good**: Normal operation (green)
- **warn**: Warning level (yellow)
- **critical**: Critical level (orange)
- **alarm**: Alert level (red - triggers notifications)

### Server Metrics Thresholds

```json
{
  "server_data": {
    "total_system_filecount": {
      "good": 500000,
      "warn": 700000,
      "critical": 850000,
      "alarm": 900000
    },
    "disk_space_usage_percent": {
      "good": 70,
      "warn": 80,
      "critical": 85,
      "alarm": 90
    }
  }
}
```

### Application Metrics Thresholds

```json
{
  "app_data": {
    "session_count": {
      "good": 100,
      "warn": 1000,
      "critical": 5000,
      "alarm": 10000
    },
    "log_filesize_kb": {
      "good": 10240,
      "warn": 51200,
      "critical": 256000,
      "alarm": 512000
    }
  }
}
```

### Alarm Trigger Configuration

Define how many events must occur before triggering an alarm notification.

```json
{
  "alarms": {
    "app_unreachable": {
      "alarm_threshold_event_count": 3
    },
    "fatal_errors_occuring": {
      "alarm_threshold_event_count": 1
    },
    "remaining_disk_space_too_low": {
      "alarm_threshold_event_count": 1
    }
  }
}
```

| Field | Description | Example |
|-------|-------------|---------|
| `alarm_threshold_event_count` | Number of events needed to trigger alarm | `3` = trigger after 3 events |

**How it works:**
- Events are logged when thresholds are exceeded
- Alarms are sent only after event count reaches threshold
- Use `1` for immediate alerts, higher values to reduce noise

See [Thresholds Reference](../reference/thresholds.md) for complete threshold configuration options.

## Event & Alarm Intervals (intervals.json)

Configure cooldown periods to prevent duplicate events and notification spam.

### Event Cooldown Intervals

Control how often the same event can be logged (in minutes).

```json
{
  "events": {
    "min_event_interval_minutes": {
      "_default": 60,
      "app_unreachable": 15,
      "fatal_errors_occuring": 72,
      "remaining_disk_space_too_low": 60
    }
  }
}
```

### Alarm Cooldown Intervals

Control how often the same alarm notification can be sent (in minutes).

```json
{
  "alarms": {
    "min_alarm_interval_minutes": {
      "_default": 1440,
      "app_unreachable": 60,
      "fatal_errors_occuring": 1440,
      "remaining_disk_space_too_low": 180
    }
  }
}
```

### Configuration Guide

| Setting | Default | Description |
|---------|---------|-------------|
| `events._default` | 60 min | Fallback for unconfigured event types |
| `alarms._default` | 1440 min (24h) | Fallback for unconfigured alarm types |
| Per-event/alarm | Custom | Override default for specific event types |

**How cooldowns work:**

1. **Event Cooldown**: Prevents logging duplicate events within the interval
   - Example: `app_unreachable: 15` = log event at most once every 15 minutes
   - Events use hash of `type|server|app|context` for deduplication

2. **Alarm Cooldown**: Prevents sending duplicate notifications within the interval
   - Example: `app_unreachable: 60` = send alarm at most once per hour
   - Alarms use hash of `type|server|app|context|channel` for per-channel tracking
   - Email and SMS cooldowns are independent

**Best practices:**
- Set event intervals short (15-60 min) to capture state changes
- Set alarm intervals longer (60-1440 min) to avoid notification fatigue
- Use `_default` values for consistent baseline behavior

## Cache TTL Configuration (ttl.json)

Configure cache timeouts for API responses and data caching (in seconds).

```json
{
  "ping": 360,
  "servers": 720,
  "apps": 300,
  "app": 300,
  "serverData": 180,
  "databases": 10,
  "readme": -1,
  "errorlog": 30,
  "serverMetricsChart": 60
}
```

| Key | Default | Purpose |
|-----|---------|---------|
| `ping` | 360 (6 min) | Server ping/availability checks |
| `servers` | 720 (12 min) | Server list and configuration |
| `apps` | 300 (5 min) | Application list data |
| `app` | 300 (5 min) | Individual application details |
| `serverData` | 180 (3 min) | Server metrics (CPU, RAM, disk) |
| `databases` | 10 | Database information |
| `readme` | -1 (disabled) | Application README files |
| `errorlog` | 30 | Error log content |
| `serverMetricsChart` | 60 (1 min) | Metrics chart data |

**Special values:**
- `-1` = Cache disabled, always fetch fresh data
- `0` = No cache, fetch immediately
- Higher values reduce API calls but may show stale data

**Performance tips:**
- Increase TTL for stable data (servers, readme)
- Decrease TTL for dynamic metrics (serverData, databases)
- Balance between API load and data freshness

## Data Retention Configuration (data_retention.json)

Configure how long server metrics are retained.

```json
{
  "raw_metrics_days": 7,
  "hourly_metrics_days": 30,
  "daily_metrics_days": 365
}
```

| Setting | Default | Description |
|---------|---------|-------------|
| `raw_metrics_days` | 7 days | Keep raw 5-minute data |
| `hourly_metrics_days` | 30 days | Keep hourly aggregates |
| `daily_metrics_days` | 365 days | Keep daily aggregates |

## Security Best Practices

!!! danger "Never Commit Secrets"
    Never commit actual configuration files containing secrets to version control. Always use `.example` files in the repository.

### Configuration File Security

```bash
# Restrict configuration directory access
chmod 750 config/

# Secure individual files
chmod 600 config/config.env
chmod 600 config/mail.config.env
chmod 600 config/servers.json
chmod 600 config/users.json
```

### API Key Management

- Use strong keys: `openssl rand -hex 32`
- Rotate keys regularly (every 90 days)
- Use different keys for each server
- Never reuse keys across environments

### Database Security

- Use a dedicated database user with minimal permissions
- Use strong passwords (20+ characters)
- Restrict database access to localhost when possible
- Enable MySQL/MariaDB SSL connections in production

## Environment-Specific Configuration

### Development Environment

```bash
DEBUG_MODE=true
RATE_LIMIT_ENABLED=false
```

### Production Environment

```bash
DEBUG_MODE=false
RATE_LIMIT_ENABLED=true
MAIL_ENABLED=true
```

## Next Steps

- [Deployment Guide](deployment.md) - Deploy to production
- [Security Guide](security.md) - Security hardening
- [Configuration Reference](../reference/configuration-reference.md) - Complete configuration schema
- [Client Configuration](../reference/client-config.md) - Configure client-side .env files
