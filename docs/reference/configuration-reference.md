# Configuration Reference

Complete schema and field descriptions for all MATA Dashboard configuration files.

## Overview

MATA Dashboard uses multiple configuration files to manage application settings, server definitions, monitoring thresholds, and email notifications. All configuration files are stored in the `config/` directory.

## Configuration Files

| File | Purpose | Format | Required |
|------|---------|--------|----------|
| `config.env` | Application settings, database, logging | ENV | Yes |
| `servers.json` | Server endpoints and API configuration | JSON | Yes |
| `mail.config.env` | Email and SMTP settings | ENV | Yes |
| `thresholds.json` | Monitoring thresholds and alarm settings | JSON | No (has defaults) |
| `ttl.json` | Cache TTL configuration | JSON | No (has defaults) |
| `data_retention.json` | Metrics retention and aggregation | JSON | No (has defaults) |
| `users.json` | User authentication data | JSON | Yes |
| `mysql.mata.env` | Optional dedicated database config | ENV | No |

---

## config.env

Main application configuration file.

### File Location
```
config/config.env
```

### Setup
```bash
cp config/example/config.env.example config/config.env
```

### Security
- Never commit to version control
- Restrict file permissions: `chmod 600 config/config.env`
- Generate secure secrets: `openssl rand -base64 64`

---

### Application Environment

#### APP_ENV
Application environment mode.

| Field | Value |
|-------|-------|
| **Type** | String |
| **Options** | `prod`, `dev`, `demo` |
| **Default** | `prod` |
| **Required** | Yes |

**Values:**
- `prod` (production): Full monitoring, real API calls, high alert sensitivity
- `dev` (development): Debug enabled, development settings
- `demo`: Uses MockServerDataCollector with demo data

**Example:**
```env
APP_ENV=prod
```

---

### Domain Configuration

#### DOMAIN
Primary domain for the dashboard application.

| Field | Value |
|-------|-------|
| **Type** | String |
| **Format** | Domain name without protocol |
| **Default** | None |
| **Required** | Yes |

**Example:**
```env
DOMAIN=mata.yourdomain.com
```

#### DIR
Subdirectory path if application is not at domain root.

| Field | Value |
|-------|-------|
| **Type** | String |
| **Format** | Path without leading/trailing slashes |
| **Default** | Empty (root) |
| **Required** | No |

**Examples:**
```env
# At domain root
DIR=

# In subdirectory
DIR=monitoring/mata
```

---

### Application Settings

#### MAINTENANCE_MODE_ENABLED
Enable/disable maintenance mode for the dashboard.

| Field | Value |
|-------|-------|
| **Type** | String |
| **Options** | `ON`, `OFF` |
| **Default** | `OFF` |
| **Required** | No |

**Example:**
```env
MAINTENANCE_MODE_ENABLED=OFF
```

#### IP_WHITELIST_ENABLED
Enable IP-based access restriction for login page protection.

| Field | Value |
|-------|-------|
| **Type** | String |
| **Options** | `ON`, `OFF` |
| **Default** | `OFF` |
| **Required** | No |

**Description:** When enabled, only IP addresses listed in `ALLOWED_IPS` can access the login page. Useful for enterprise environments with known static IP addresses.

**Example:**
```env
IP_WHITELIST_ENABLED=OFF
```

#### ALLOWED_IPS
Comma-separated list of allowed IP addresses when IP whitelisting is enabled.

| Field | Value |
|-------|-------|
| **Type** | String |
| **Format** | Comma-separated IP addresses (IPv4 and IPv6 supported) |
| **Default** | `127.0.0.1,::1` |
| **Required** | If IP_WHITELIST_ENABLED=ON |

**Description:** When `IP_WHITELIST_ENABLED=ON`, only these IP addresses can access the login page. All other IPs will receive a 403 Forbidden response. The feature applies only to login routes, not the entire application.

**Example:**
```env
ALLOWED_IPS=127.0.0.1,::1,192.168.1.100
```

#### DASHBOARD_SERVER_COLUMNS
Number of columns in the main server grid layout.

| Field | Value |
|-------|-------|
| **Type** | Integer |
| **Range** | 1-4 |
| **Default** | 2 |
| **Required** | No |

**Example:**
```env
DASHBOARD_SERVER_COLUMNS=2
```

#### DASHBOARD_CONTAINER_WIDTH
Dashboard container width in pixels.

| Field | Value |
|-------|-------|
| **Type** | Integer |
| **Default** | 1344 (Bulma default) |
| **Required** | No |

**Example:**
```env
DASHBOARD_CONTAINER_WIDTH=1344
```

---

### PHP Runtime Settings

#### DISPLAY_ERRORS
Display PHP errors in output.

| Field | Value |
|-------|-------|
| **Type** | Integer |
| **Options** | `0` (disabled), `1` (enabled) |
| **Default** | `1` |
| **Production** | `0` |
| **Required** | No |

**Example:**
```env
# Development
DISPLAY_ERRORS=1

# Production
DISPLAY_ERRORS=0
```

#### LOG_LEVEL
PHP error logging level.

| Field | Value |
|-------|-------|
| **Type** | String |
| **Options** | `notices`, `warnings`, `errors` |
| **Default** | `notices` |
| **Required** | No |

**Example:**
```env
LOG_LEVEL=errors
```

---

### Application Logging

#### APP_LOG_PATH
Application log file path (relative to app root or absolute).

| Field | Value |
|-------|-------|
| **Type** | String |
| **Default** | `logs/app.log` |
| **Required** | No |

**Example:**
```env
APP_LOG_PATH=logs/app.log
```

#### MONOLOG_LEVEL
Monolog log level.

| Field | Value |
|-------|-------|
| **Type** | String |
| **Options** | `debug`, `info`, `notice`, `warning`, `error`, `critical`, `alert`, `emergency` |
| **Default** | `debug` |
| **Required** | No |

**Example:**
```env
MONOLOG_LEVEL=warning
```

---

### Database Configuration

#### DB_HOST
Database server hostname or IP address.

| Field | Value |
|-------|-------|
| **Type** | String |
| **Default** | `localhost` |
| **Required** | Yes |

**Example:**
```env
DB_HOST=localhost
```

#### DB_PORT
Database server port.

| Field | Value |
|-------|-------|
| **Type** | Integer |
| **Default** | `3306` |
| **Required** | Yes |

**Example:**
```env
DB_PORT=3306
```

#### DB_DATABASE
Database name.

| Field | Value |
|-------|-------|
| **Type** | String |
| **Default** | `mata_dashboard` |
| **Required** | Yes |

**Example:**
```env
DB_DATABASE=mata_dashboard
```

#### DB_USERNAME
Database username.

| Field | Value |
|-------|-------|
| **Type** | String |
| **Required** | Yes |

**Example:**
```env
DB_USERNAME=mata_user
```

#### DB_PASSWORD
Database password.

| Field | Value |
|-------|-------|
| **Type** | String |
| **Required** | Yes |
| **Security** | Strong password required |

**Example:**
```env
DB_PASSWORD=your_secure_database_password
```

#### DB_CHARSET
Database character set.

| Field | Value |
|-------|-------|
| **Type** | String |
| **Default** | `utf8mb4` |
| **Required** | No |

**Example:**
```env
DB_CHARSET=utf8mb4
```

#### DB_COLLATION
Database collation.

| Field | Value |
|-------|-------|
| **Type** | String |
| **Default** | `utf8mb4_unicode_ci` |
| **Required** | No |

**Example:**
```env
DB_COLLATION=utf8mb4_unicode_ci
```

---

### Template System

#### TEMPLATE_DEBUG_ENABLED
Enable Twig template debugging.

| Field | Value |
|-------|-------|
| **Type** | String |
| **Options** | `ON`, `OFF` |
| **Default** | `OFF` |
| **Production** | `OFF` |
| **Required** | No |

**Example:**
```env
# Development
TEMPLATE_DEBUG_ENABLED=ON

# Production
TEMPLATE_DEBUG_ENABLED=OFF
```

---

## servers.json

Server monitoring configuration with API endpoints and credentials.

### File Location
```
config/servers.json
```

### Setup
```bash
cp config/example/servers.json.example config/servers.json
```

### Security
- Never commit to version control
- Generate secure API keys: `openssl rand -hex 32`
- Restrict file permissions: `chmod 600 config/servers.json`

### Schema

```json
{
    "server-identifier": {
        "title": "Display Name",
        "active": true,
        "url": "https://server.com/",
        "api_url": "https://server.com/mata-node/api",
        "api_key": "32_character_api_key_here",
        "primary_color": "#28a745",
        "secondary_color": "#ffffff",
        "info": "Server description",
        "check_disk_space": "on"
    }
}
```

---

### Server Configuration Fields

#### Server Identifier (Key)
Unique identifier for the server (object key).

| Field | Value |
|-------|-------|
| **Type** | String |
| **Format** | Lowercase, hyphen-separated |
| **Required** | Yes (as JSON key) |

**Example:**
```json
{
    "production-web-server": { ... }
}
```

#### title
Human-readable server display name.

| Field | Value |
|-------|-------|
| **Type** | String |
| **Required** | No (defaults to identifier) |

**Example:**
```json
"title": "Production Web Server"
```

#### active
Enable/disable monitoring for this server.

| Field | Value |
|-------|-------|
| **Type** | Boolean |
| **Default** | `true` |
| **Required** | No |

**Example:**
```json
"active": true
```

**Usage:**
- `true`: Server is monitored, shown in dashboard
- `false`: Server is ignored, hidden from dashboard

#### url
Base URL of the server.

| Field | Value |
|-------|-------|
| **Type** | String |
| **Format** | Full URL with protocol |
| **Required** | Yes |

**Examples:**
```json
"url": "https://app.example.com/"
"url": "http://192.168.1.100/"
"url": "https://server.com"
```

#### api_url
Full URL to the MATA Node API endpoint.

| Field | Value |
|-------|-------|
| **Type** | String |
| **Format** | Full URL with protocol |
| **Required** | Yes |

**Example:**
```json
"api_url": "https://server.com/mata-node/api"
```

#### api_key
API key for authentication with the node.

| Field | Value |
|-------|-------|
| **Type** | String |
| **Format** | 32+ character random string |
| **Required** | Yes |
| **Security** | Must match node configuration |

**Generate:**
```bash
openssl rand -hex 32
```

**Example:**
```json
"api_key": "a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6"
```

#### primary_color
Primary color for server identification in dashboard.

| Field | Value |
|-------|-------|
| **Type** | String |
| **Format** | Hex color code |
| **Required** | No |
| **Default** | `#3273dc` (Bulma blue) |

**Examples:**
```json
"primary_color": "#28a745"  // Green
"primary_color": "#ffc107"  // Yellow
"primary_color": "#dc3545"  // Red
```

**Recommended Colors:**

| Purpose | Color | Hex Code |
|---------|-------|----------|
| Production | Green | `#28a745` |
| Staging | Yellow | `#ffc107` |
| Development | Blue | `#17a2b8` |
| Database | Purple | `#6f42c1` |
| File Server | Orange | `#fd7e14` |

#### secondary_color
Secondary color for UI theming.

| Field | Value |
|-------|-------|
| **Type** | String |
| **Format** | Hex color code |
| **Required** | No |
| **Default** | `#ffffff` (white) |

**Example:**
```json
"secondary_color": "#ffffff"
```

#### info
Human-readable description of the server's purpose.

| Field | Value |
|-------|-------|
| **Type** | String |
| **Required** | No |

**Example:**
```json
"info": "Production Web Server - Main Application"
```

#### check_disk_space
Enable/disable disk space monitoring.

| Field | Value |
|-------|-------|
| **Type** | String |
| **Options** | `on`, `off` |
| **Default** | `on` |
| **Required** | No |

**Example:**
```json
"check_disk_space": "on"
```

---

### Complete Server Example

```json
{
    "production-web-server": {
        "title": "Production Server",
        "active": true,
        "url": "https://app.example.com/",
        "api_url": "https://app.example.com/mata-node/api",
        "api_key": "a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6",
        "primary_color": "#28a745",
        "secondary_color": "#ffffff",
        "info": "Production Web Server - Main Application",
        "check_disk_space": "on"
    }
}
```

---

## mail.config.env

Email and SMTP configuration for notifications.

### File Location
```
config/mail.config.env
```

### Setup
```bash
cp config/example/mail.config.env.example config/mail.config.env
```

### Security
- Never commit to version control
- Restrict file permissions: `chmod 600 config/mail.config.env`
- Use app-specific passwords for Gmail/other providers

---

### Email Recipients

#### MAIL_TEST_RECIPIENTS
Test email recipients for testing functionality.

| Field | Value |
|-------|-------|
| **Type** | String |
| **Format** | Comma-separated email addresses |
| **Required** | No |

**Example:**
```env
MAIL_TEST_RECIPIENTS=admin@example.com,test@example.com
```

#### MAIL_ALERT_RECIPIENTS
Alert recipients for monitoring notifications.

| Field | Value |
|-------|-------|
| **Type** | String |
| **Format** | Comma-separated email addresses |
| **Required** | Yes |

**Example:**
```env
MAIL_ALERT_RECIPIENTS=alerts@example.com,admin@example.com
```

#### MAIL_DEV_RECIPIENTS
Development team recipients.

| Field | Value |
|-------|-------|
| **Type** | String |
| **Format** | Comma-separated email addresses |
| **Required** | No |

**Example:**
```env
MAIL_DEV_RECIPIENTS=dev-team@example.com
```

---

### Mailer Configuration

#### MAILER_APP
Production mailer type.

| Field | Value |
|-------|-------|
| **Type** | String |
| **Options** | `symfony`, `phpmailer`, `sendgrid` |
| **Default** | `symfony` |
| **Required** | Yes |

**Example:**
```env
MAILER_APP=symfony
```

#### MAILER_DEV
Development mailer type.

| Field | Value |
|-------|-------|
| **Type** | String |
| **Options** | `symfony`, `phpmailer`, `sendgrid` |
| **Default** | `phpmailer` |
| **Required** | No |

**Example:**
```env
MAILER_DEV=phpmailer
```

---

### SMTP Configuration (PHPMailer)

#### SMTP_DEV_HOST
SMTP server hostname.

| Field | Value |
|-------|-------|
| **Type** | String |
| **Required** | If using PHPMailer |

**Example:**
```env
SMTP_DEV_HOST=smtp.gmail.com
```

#### SMTP_DEV_PORT
SMTP server port.

| Field | Value |
|-------|-------|
| **Type** | Integer |
| **Common Values** | `587` (TLS), `465` (SSL), `25` (unencrypted) |
| **Required** | If using PHPMailer |

**Example:**
```env
SMTP_DEV_PORT=587
```

#### SMTP_DEV_USER
SMTP username.

| Field | Value |
|-------|-------|
| **Type** | String |
| **Required** | If using PHPMailer |

**Example:**
```env
SMTP_DEV_USER=your-email@gmail.com
```

#### SMTP_DEV_PASSWORD
SMTP password.

| Field | Value |
|-------|-------|
| **Type** | String |
| **Required** | If using PHPMailer |
| **Security** | Use app-specific passwords |

**Example:**
```env
SMTP_DEV_PASSWORD=your-app-specific-password
```

#### SMTP_DEV_SECURE
SMTP encryption method.

| Field | Value |
|-------|-------|
| **Type** | String |
| **Options** | `tls`, `ssl`, `` (none) |
| **Default** | `tls` |
| **Required** | No |

**Example:**
```env
SMTP_DEV_SECURE=tls
```

#### SMTP_DEV_FROM
From email address.

| Field | Value |
|-------|-------|
| **Type** | String |
| **Format** | Valid email address |
| **Required** | If using PHPMailer |

**Example:**
```env
SMTP_DEV_FROM=noreply@example.com
```

#### SMTP_DEV_FROM_NAME
From display name.

| Field | Value |
|-------|-------|
| **Type** | String |
| **Default** | `MATA Dashboard` |
| **Required** | No |

**Example:**
```env
SMTP_DEV_FROM_NAME=MATA Dashboard
```

---

### Symfony Mailer Configuration

#### SYM_MAILER_DSN
Symfony Mailer DSN connection string.

| Field | Value |
|-------|-------|
| **Type** | String |
| **Format** | DSN connection string |
| **Required** | If using Symfony Mailer |

**Format Examples:**
```env
# SMTP
SYM_MAILER_DSN=smtp://username:password@smtp.example.com:587

# Gmail
SYM_MAILER_DSN=gmail://username:password@default

# SendGrid
SYM_MAILER_DSN=sendgrid://api_key@default

# Postmark
SYM_MAILER_DSN=postmark://token@default
```

#### SYM_FROM_EMAIL
From email address for Symfony Mailer.

| Field | Value |
|-------|-------|
| **Type** | String |
| **Format** | Valid email address |
| **Required** | If using Symfony Mailer |

**Example:**
```env
SYM_FROM_EMAIL=noreply@example.com
```

#### SYM_FROM_NAME
From display name for Symfony Mailer.

| Field | Value |
|-------|-------|
| **Type** | String |
| **Default** | `MATA Dashboard` |
| **Required** | No |

**Example:**
```env
SYM_FROM_NAME=MATA Dashboard
```

---

### Email Settings

#### MAIL_CHARSET
Email character encoding.

| Field | Value |
|-------|-------|
| **Type** | String |
| **Default** | `utf-8` |
| **Required** | No |

**Example:**
```env
MAIL_CHARSET=utf-8
```

#### MAIL_WORD_WRAP
Word wrap length for email content.

| Field | Value |
|-------|-------|
| **Type** | Integer |
| **Default** | `75` |
| **Required** | No |

**Example:**
```env
MAIL_WORD_WRAP=75
```

#### MAIL_HTML_ENABLED
Enable HTML emails.

| Field | Value |
|-------|-------|
| **Type** | Boolean |
| **Default** | `true` |
| **Required** | No |

**Example:**
```env
MAIL_HTML_ENABLED=true
```

#### MAIL_LOG_ONLY
Log emails instead of sending (development).

| Field | Value |
|-------|-------|
| **Type** | Boolean |
| **Default** | `false` |
| **Required** | No |

**Example:**
```env
# Development - don't send emails
MAIL_LOG_ONLY=true

# Production - send emails
MAIL_LOG_ONLY=false
```

---

## ttl.json

Cache TTL (Time To Live) configuration in seconds.

### File Location
```
config/ttl.json
```

### Setup
```bash
cp config/example/ttl.json.example config/ttl.json
```

### Schema

```json
{
    "ping": 360,
    "servers": 720,
    "apps": 300,
    "app": 300,
    "serverData": 180,
    "databases": 10,
    "readme": -1,
    "errorlog": 30
}
```

### Cache Type Fields

| Field | Default (seconds) | Description |
|-------|-------------------|-------------|
| `ping` | 360 (6 min) | Server ping response cache |
| `servers` | 720 (12 min) | Server list cache |
| `apps` | 300 (5 min) | Application list cache |
| `app` | 300 (5 min) | Single app data cache |
| `serverData` | 180 (3 min) | Server metrics cache |
| `databases` | 10 (10 sec) | Database info cache |
| `readme` | -1 (disabled) | README file cache |
| `errorlog` | 30 (30 sec) | Error log cache |

**Special Values:**
- `-1`: Cache disabled (always fetch fresh)
- `0`: No caching
- `> 0`: Cache duration in seconds

---

## data_retention.json

Server metrics retention and aggregation settings.

### File Location
```
config/data_retention.json
```

### Setup
```bash
cp config/data_retention.json.example config/data_retention.json
```

### Schema

```json
{
    "raw_retention_days": 30,
    "aggregation": {
        "hourly": {
            "lag_minutes": 5,
            "catch_up_hours": 24
        },
        "daily": {
            "lag_minutes": 30
        }
    },
    "pruning": {
        "batch_limit": 5000
    },
    "ingestion_health": {
        "stall_minutes": 15,
        "log_rotate_megabytes": 5,
        "log_rotate_files": 3
    }
}
```

### Configuration Fields

#### raw_retention_days
Days to retain raw (5-minute) metrics.

| Field | Value |
|-------|-------|
| **Type** | Integer |
| **Default** | 30 |
| **Range** | 7-365 |

#### aggregation.hourly.lag_minutes
Minutes to wait before aggregating hourly data.

| Field | Value |
|-------|-------|
| **Type** | Integer |
| **Default** | 5 |

#### aggregation.hourly.catch_up_hours
Maximum past hours to aggregate in single run.

| Field | Value |
|-------|-------|
| **Type** | Integer |
| **Default** | 24 |

#### aggregation.daily.lag_minutes
Minutes to wait after midnight before daily aggregation.

| Field | Value |
|-------|-------|
| **Type** | Integer |
| **Default** | 30 |

#### pruning.batch_limit
Maximum rows to delete per pruning run.

| Field | Value |
|-------|-------|
| **Type** | Integer |
| **Default** | 5000 |

---

## users.json

User authentication data (JSON-based auth).

### File Location
```
config/users.json
```

### Security
- **CRITICAL:** Never commit to version control
- Restrict permissions: `chmod 600 config/users.json`
- Use bcrypt password hashes only

### Generate Password Hash

```bash
php scripts/hash-password.php
```

### Schema

```json
{
    "users": [
        {
            "username": "admin",
            "password": "$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi",
            "email": "admin@example.com",
            "role": "admin"
        }
    ]
}
```

---

## Best Practices

### 1. Security
- Never commit configuration files with secrets
- Use `.gitignore` for all config files
- Restrict file permissions (600 or 640)
- Rotate API keys and secrets regularly
- Use environment-specific configurations

### 2. Organization
- Keep example files updated
- Document all configuration changes
- Use consistent naming conventions
- Maintain separate dev/staging/prod configs

### 3. Validation
- Validate JSON syntax before deploying
- Test configuration changes in staging
- Implement configuration validation in application
- Log configuration load errors

### 4. Backup
- Backup configuration files regularly
- Store backups securely (encrypted)
- Document configuration recovery procedures
- Test configuration restore process

---

## Related Documentation

- [Server Metrics Reference](./server-metrics.md)
- [Application Monitoring Reference](./application-monitoring.md)
- [Event System Reference](./event-system.md)
- [Threshold Configuration](./thresholds.md)

---

**Last Updated:** 2025-11-02
**Version:** 1.0
