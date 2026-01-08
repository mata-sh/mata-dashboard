# Client-Side Configuration Reference

Complete reference for configuring `.env` configuration files on monitored applications (nodes/clients).

## Overview

MATA Dashboard supports reading `.env` configuration files from monitored applications to collect environment information, maintenance status, and other application-specific settings. This allows the dashboard to:

- Identify application environment (development, staging, production)
- Detect maintenance mode status
- Monitor configuration-based settings
- Track environment-specific behaviors
- Collect application metadata

## How It Works

### Configuration Flow

```
Client Application (.env files)
         ↓
Node Configuration (shops.json)
         ↓
ShopWatcher (loadShopConfig)
         ↓
Dashboard (transmitted via API)
```

**Step 1: Client Application**
- Application stores configuration in `.env` files
- Multiple config files can exist (base, security, mail, etc.)
- Files follow standard `.env` format

**Step 2: Node Configuration**
- Node's `shops.json` specifies which config files to read
- Config file paths are relative to application root
- Multiple files are merged in order

**Step 3: Dashboard Collection**
- ShopWatcher reads specified config files
- Parses and merges configuration data
- Filters values based on `transmit.json` whitelist
- Transmits to dashboard via API

---

## Configuration File Format

### File Structure

Configuration files use the standard `.env` format:

```env
# Comments start with # and are ignored
KEY=VALUE

# Values with commas are automatically converted to arrays
COMMA_SEPARATED=value1,value2,value3

# Standard key-value pairs
SIMPLE_VALUE=some_value
```

### Parsing Rules

The configuration parser follows these rules:

| Rule | Description | Example |
|------|-------------|---------|
| **Comments** | Lines starting with `#` are ignored | `# This is a comment` |
| **Format** | Key-value pairs separated by `=` | `SHOP_ENV=production` |
| **Whitespace** | Leading/trailing whitespace is trimmed | `  KEY = VALUE  ` → `KEY=VALUE` |
| **Empty Lines** | Blank lines are skipped | - |
| **Arrays** | Comma-separated values become arrays | `ALLOWED_IPS=192.168.1.1,192.168.1.2` |
| **No Quotes** | Values don't need quotes (they're stripped) | `VALUE=test` not `VALUE="test"` |

### Example Configuration File

**Basic Application Config (`config/config.env`):**
```env
# Application Environment
SHOP_ENV=production
DOMAIN=example.com
DIR=shop/api

# Maintenance Settings
MAINTENANCE_ENABLED=OFF

# PHP Settings
DISPLAY_ERRORS=0
LOG_LEVEL=notices
```

**Security Config (`config/security.config.env`):**
```env
# IP Whitelisting
IP_WHITELIST_ENABLED=OFF
ALLOWED_IPS=203.0.113.10

# Secrets
FILEPATH_SECRET=your_secret_key
WATCHER_API_KEY=your_api_key
```

**Logging Config (`config/log.config.env`):**
```env
# Application log file path (relative to app root or absolute)
APP_LOG_PATH=logs/app.log

# Monolog level: debug, info, notice, warning, error, critical, alert, emergency
MONOLOG_LEVEL=debug
```

---

## Configuring the Node

### Shop Configuration File

On the MATA node (monitoring server), you define which config files to read in the shop configuration JSON file (typically `config/shops.json`).

**File Location:**
```
/path/to/mata-node/config/shops.json
```

**Configuration Structure:**

```json
{
  "shop-name": {
    "title": "Shop Display Name",
    "url": "https://shop-url.com",
    "path": "shop-directory",
    "logs": [
      "shop-directory/logs/error.log"
    ],
    "configs": [
      "shop-directory/config/config.env",
      "shop-directory/config/security.config.env",
      "shop-directory/config/mail.config.env"
    ]
  }
}
```

### Configuration Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `shop-name` | String | Yes | Unique identifier for the shop/application |
| `title` | String | Yes | Human-readable display name |
| `url` | String | Yes | Application URL for HTTP ping monitoring |
| `path` | String | Yes | Relative path to application root directory |
| `logs` | Array | No | Paths to log files to monitor |
| `configs` | Array | No | Paths to `.env` configuration files to read |

### Path Resolution

**Important:** All paths in `configs` array are **relative to the node's application root path**.

**Example Directory Structure:**
```
/var/www/
├── mata-node/              # MATA node installation
│   └── config/
│       └── shops.json      # Shop configuration
└── applications/
    ├── shop1/              # Application root (path: "applications/shop1")
    │   └── config/
    │       ├── config.env
    │       └── security.config.env
    └── shop2/
        └── config/
            └── config.env
```

**Corresponding Configuration:**
```json
{
  "shop1": {
    "title": "Shop 1",
    "path": "applications/shop1",
    "configs": [
      "applications/shop1/config/config.env",
      "applications/shop1/config/security.config.env"
    ]
  },
  "shop2": {
    "title": "Shop 2",
    "path": "applications/shop2",
    "configs": [
      "applications/shop2/config/config.env"
    ]
  }
}
```

### Multi-File Configuration

**Loading Order:**
Configuration files are loaded and merged in the order specified. Later files override earlier files for duplicate keys.

**Example:**
```json
{
  "myshop": {
    "configs": [
      "myshop/config/config.env",        # Base config (loaded first)
      "myshop/config/security.config.env", # Security overrides
      "myshop/config/local.config.env"   # Local overrides (loaded last)
    ]
  }
}
```

**Merging Behavior:**
```env
# config.env
SHOP_ENV=staging
DISPLAY_ERRORS=1
LOG_LEVEL=warnings

# local.config.env
SHOP_ENV=production  # Overrides staging
DISPLAY_ERRORS=0     # Overrides 1
# LOG_LEVEL remains "warnings" (not overridden)
```

**Result:**
```
SHOP_ENV=production
DISPLAY_ERRORS=0
LOG_LEVEL=warnings
```

---

## Supported Configuration Values

### Transmitted Values

The following configuration values are read from client `.env` files and transmitted to the dashboard. These are defined in the node's `config/transmit.json` file.

| Configuration Key | Type | Description | Example Values |
|-------------------|------|-------------|----------------|
| `APP_ENV` | String | Application environment | `dev`, `staging`, `production` |
| `DOMAIN` | String | Primary domain name | `example.com` |
| `DIR` | String | Application directory path | `shop/api`, `/var/www/app` |
| `SESSION_PATH` | String | Custom session storage path | `shop/sessions`, `/tmp/sessions` |
| `MAINTENANCE_ENABLED` | String | Maintenance mode status | `ON`, `OFF` |
| `IP_WHITELIST_ENABLED` | String | IP whitelisting feature status | `ON`, `OFF` |
| `DISPLAY_ERRORS` | String | PHP display_errors setting | `0` (off), `1` (on) |
| `LOG_LEVEL` | String | Application logging level | `errors`, `warnings`, `notices`, `debug` |
| `APP_LOG_PATH` | String | Application log file path | `logs/app.log`, `/var/log/mata/app.log` |
| `MONOLOG_LEVEL` | String | Monolog log level | `debug`, `info`, `warning`, `error` |

### Value Types

**String Values:**
```env
SHOP_ENV=production
DOMAIN=example.com
```

**Boolean-like Values:**
```env
MAINTENANCE_ENABLED=ON
DISPLAY_ERRORS=0
MONOLOG_LEVEL=debug
```

**Array Values (comma-separated):**
```env
ALLOWED_IPS=192.168.1.1,192.168.1.2,10.0.0.1
MAIL_ALERT_RECIPIENTS=ops@example.com,dev@example.com
```

### Additional Supported Values

These values can be stored in config files but are **not transmitted** to the dashboard (used locally by the application):

| Configuration Key | Description |
|-------------------|-------------|
| `MAINTENANCE_MODE_ENABLED` | Alternative maintenance flag |
| `MINIFYING` | Asset minification setting |
| `FILEPATH_SECRET` | File path encryption secret |
| `WATCHER_API_KEY` | API authentication key for node |

---

## Implementation Details

### Configuration Loading

Configuration loading is implemented in `ShopWatcher.php` (lines 148-166) using the `loadShopConfig()` method.

**Process:**
1. Read shop configuration from `shops.json`
2. Extract `configs` array containing file paths
3. Loop through each config file path
4. Load each file using `Tools::loadEnv()`
5. Merge configurations using `array_merge()`
6. Filter values against `transmit.json` whitelist
7. Return JSON-encoded configuration data

### Parsing Implementation

The `.env` parser is implemented in `src/Utils/Tools.php` (lines 6-28).

**Parser Logic:**
```php
function loadEnv($filePath) {
    $config = [];

    if (!file_exists($filePath)) {
        return $config;
    }

    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($lines as $line) {
        // Skip comments
        if (strpos(trim($line), '#') === 0) {
            continue;
        }

        // Parse KEY=VALUE
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            // Convert comma-separated to array
            if (strpos($value, ',') !== false) {
                $value = array_map('trim', explode(',', $value));
            }

            $config[$key] = $value;
        }
    }

    return $config;
}
```

---

## Usage Examples

### Example 1: Basic Single-Application Setup

**Application Structure:**
```
/var/www/myshop/
├── config/
│   └── config.env
├── logs/
│   └── error.log
└── public/
    └── index.php
```

**Client Config File (`/var/www/myshop/config/config.env`):**
```env
# Application Settings
SHOP_ENV=production
DOMAIN=myshop.com
DIR=/var/www/myshop

# Maintenance
MAINTENANCE_ENABLED=OFF

# PHP Configuration
DISPLAY_ERRORS=0
LOG_LEVEL=errors
```

**Node Configuration (`config/shops.json`):**
```json
{
  "myshop": {
    "title": "My Shop",
    "url": "https://myshop.com",
    "path": "/var/www/myshop",
    "logs": ["/var/www/myshop/logs/error.log"],
    "configs": ["/var/www/myshop/config/config.env"]
  }
}
```

**Result:**
Dashboard receives and displays:
- Environment: production
- Domain: myshop.com
- Maintenance: OFF
- Display Errors: 0
- Log Level: errors

---

### Example 2: Multi-Environment Configuration

**Application Structure:**
```
/var/www/myapp/
├── config/
│   ├── config.env          # Base configuration
│   ├── config.dev.env      # Development overrides
│   └── config.prod.env     # Production overrides
└── logs/
    └── app.log
```

**Base Config (`config/config.env`):**
```env
# Base Settings
DOMAIN=myapp.com
APP_LOG_PATH=logs/app.log
MONOLOG_LEVEL=warning
```

**Development Config (`config/config.dev.env`):**
```env
SHOP_ENV=dev
DISPLAY_ERRORS=1
MAINTENANCE_ENABLED=OFF
LOG_LEVEL=debug
```

**Production Config (`config/config.prod.env`):**
```env
SHOP_ENV=production
DISPLAY_ERRORS=0
MAINTENANCE_ENABLED=OFF
LOG_LEVEL=errors
```

**Node Configuration (Development Server):**
```json
{
  "myapp-dev": {
    "title": "My App - Development",
    "url": "https://dev.myapp.com",
    "path": "/var/www/myapp",
    "configs": [
      "/var/www/myapp/config/config.env",
      "/var/www/myapp/config/config.dev.env"
    ]
  }
}
```

**Node Configuration (Production Server):**
```json
{
  "myapp-prod": {
    "title": "My App - Production",
    "url": "https://myapp.com",
    "path": "/var/www/myapp",
    "configs": [
      "/var/www/myapp/config/config.env",
      "/var/www/myapp/config/config.prod.env"
    ]
  }
}
```

---

### Example 3: Multi-Application Node

**Directory Structure:**
```
/var/www/
├── app1/
│   └── config/
│       ├── config.env
│       └── security.config.env
├── app2/
│   └── config/
│       └── config.env
└── app3/
    └── config/
        ├── config.env
        └── mail.config.env
```

**Node Configuration (`config/shops.json`):**
```json
{
  "app1": {
    "title": "Application 1",
    "url": "https://app1.example.com",
    "path": "/var/www/app1",
    "configs": [
      "/var/www/app1/config/config.env",
      "/var/www/app1/config/security.config.env"
    ]
  },
  "app2": {
    "title": "Application 2",
    "url": "https://app2.example.com",
    "path": "/var/www/app2",
    "configs": [
      "/var/www/app2/config/config.env"
    ]
  },
  "app3": {
    "title": "Application 3",
    "url": "https://app3.example.com",
    "path": "/var/www/app3",
    "configs": [
      "/var/www/app3/config/config.env",
      "/var/www/app3/config/mail.config.env"
    ]
  }
}
```

---

## Error Handling

### Missing Configuration Files

**Behavior:** If a config file doesn't exist, it's **silently skipped** without error.

**Example:**
```json
{
  "configs": [
    "/var/www/app/config/config.env",        # Exists
    "/var/www/app/config/local.config.env"   # Doesn't exist - skipped
  ]
}
```

**Result:** Only `config.env` is loaded; no error is raised for missing `local.config.env`.

### Invalid File Format

**Behavior:** Malformed lines are skipped during parsing.

**Example:**
```env
VALID_KEY=value
This line has no equals sign
ANOTHER_KEY=another_value
=value_without_key
```

**Result:**
```
VALID_KEY=value
ANOTHER_KEY=another_value
```

### Missing Configuration Keys

**Behavior:** Keys not found in config files return **empty values** in transmitted data.

**Example:**
If `SHOP_ENV` is not defined in any config file, the dashboard receives:
```json
{
  "SHOP_ENV": "",
  "DOMAIN": "example.com",
  ...
}
```

### File Permission Issues

**Problem:** Config files not readable by the MATA node process.

**Error:** File is skipped (same as missing file).

**Resolution:**
```bash
# Check file permissions
ls -la /var/www/app/config/

# Fix permissions (make readable)
chmod 644 /var/www/app/config/*.env

# Verify node process user can read
sudo -u www-data cat /var/www/app/config/config.env
```

---

## Security Considerations

### 1. Sensitive Data Protection

**Best Practice:** Store sensitive data in separate config files.

```
config/
├── config.env              # General settings (can be versioned)
└── security.config.env     # Secrets (NEVER version)
```

**Example:**
```env
# security.config.env
FILEPATH_SECRET=my_secret_key_here
WATCHER_API_KEY=api_key_12345
DATABASE_PASSWORD=super_secret_password
```

### 2. File Permissions

**Recommended Permissions:**
```bash
# Config files should be readable by web server user only
chmod 640 /var/www/app/config/*.env
chown www-data:www-data /var/www/app/config/*.env
```

**Permission Breakdown:**
- Owner (www-data): Read + Write (6)
- Group (www-data): Read (4)
- Others: No access (0)

### 3. Web Server Access

**Critical:** Ensure config files are **not accessible** via web browser.

**Apache `.htaccess`:**
```apache
<Files "*.env">
    Require all denied
</Files>
```

**Nginx Configuration:**
```nginx
location ~ /\.env {
    deny all;
    return 404;
}
```

**Test:**
```bash
curl https://myapp.com/config/config.env
# Should return 403 Forbidden or 404 Not Found
```

### 4. Version Control

**Git Ignore:**
Always add sensitive config files to `.gitignore`:

```gitignore
# Sensitive configuration
*.config.env
security.config.env
local.config.env

# Keep example/template files
!config.example.env
```

**Example Template:**
```env
# config.example.env (safe to commit)
SHOP_ENV=production
DOMAIN=example.com
# Copy to config.env and customize
```

### 5. API Authentication

Node API authentication uses HMAC tokens derived from `WATCHER_API_KEY`.

---

## Dashboard Integration

### Viewing Configuration

**Dashboard UI:**
1. Navigate to "Servers" → Select Server → Select Shop
2. View "Configuration" tab
3. See transmitted configuration values

**API Endpoint:**
```
GET /api/shops/{shop_name}/config
```

**Example Response:**
```json
{
  "shop_name": "myshop",
  "config": {
    "SHOP_ENV": "production",
    "DOMAIN": "myshop.com",
    "DIR": "/var/www/myshop",
    "MAINTENANCE_ENABLED": "OFF",
    "DISPLAY_ERRORS": "0",
    "LOG_LEVEL": "errors",
    "APP_LOG_PATH": "logs/app.log",
    "MONOLOG_LEVEL": "error"
  },
  "last_updated": "2025-11-02T14:30:00Z"
}
```

### Configuration Changes

**Automatic Updates:**
Configuration is re-read every time `getShopInfo()` is called (typically every 5 minutes).

**Manual Refresh:**
Use the dashboard's "Refresh" button to force immediate config reload.

**Change Detection:**
Dashboard can detect and alert on configuration changes if enabled:
```json
{
  "alerts": {
    "config_changed": {
      "enabled": true,
      "notify_on": ["SHOP_ENV", "MAINTENANCE_ENABLED"]
    }
  }
}
```

---

## Troubleshooting

### Configuration Not Showing in Dashboard

**Checklist:**
1. ✓ Config file exists at specified path
2. ✓ Config file is readable by node process user
3. ✓ Path in `shops.json` is correct (relative to node root)
4. ✓ Config keys match those in `transmit.json`
5. ✓ No syntax errors in `.env` file

**Debug:**
```bash
# Test file exists and is readable
ls -la /var/www/app/config/config.env

# Check file permissions
stat /var/www/app/config/config.env

# Verify node can read the file
sudo -u www-data cat /var/www/app/config/config.env

# Check shops.json syntax
cat /path/to/mata-node/config/shops.json | jq .
```

### Configuration Values Not Updating

**Possible Causes:**
1. **Caching:** Dashboard caches config data
2. **Wrong File:** Editing wrong config file
3. **File Order:** Earlier file overriding later file

**Resolution:**
```bash
# 1. Clear dashboard cache
# Use dashboard "Refresh" button

# 2. Verify correct file
grep "SHOP_ENV" /var/www/app/config/*.env

# 3. Check merge order in shops.json
cat config/shops.json | jq '.myshop.configs'
```

### Array Values Not Parsing

**Problem:**
```env
MAIL_ALERT_RECIPIENTS=ops@example.com,dev@example.com
# Dashboard shows: "ops@example.com,dev@example.com" (string, not array)
```

**Cause:** Parser automatically converts comma-separated values to arrays.

**Verify Parser Output:**
The parser should split this into:
```php
["ops@example.com", "dev@example.com"]
```

**If Not Working:**
- Check for extra spaces: `value1 , value2` (parser trims spaces)
- Verify parser version supports array conversion

---

## Best Practices

### 1. Configuration Organization

**Recommended Structure:**
```
config/
├── config.env              # Base application settings
├── security.config.env     # Secrets and security settings
├── mail.config.env         # Mail server configuration
├── logging.config.env      # Logging configuration
└── config.example.env      # Template for new installations
```

### 2. Environment-Specific Configs

**Strategy:** Use environment-specific files that override base config.

```json
{
  "production": {
    "configs": [
      "app/config/config.env",
      "app/config/config.prod.env"
    ]
  },
  "staging": {
    "configs": [
      "app/config/config.env",
      "app/config/config.staging.env"
    ]
  }
}
```

### 3. Naming Conventions

**Use Clear, Consistent Names:**
- `SHOP_ENV` not `ENV` or `ENVIRONMENT` or `APP_ENV`
- `MAINTENANCE_ENABLED` not `MAINT` or `MAINTENANCE` or `MAINTENANCE_MODE`
- Use `SCREAMING_SNAKE_CASE` for all keys

### 4. Documentation

**Document Your Config:**
```env
# Application Environment
# Values: dev, staging, production
SHOP_ENV=production

# Primary Domain
# Used for: URL generation, CORS, cookies
DOMAIN=myshop.com

# Maintenance Mode
# Values: ON, OFF
# When ON: Site shows maintenance page
MAINTENANCE_ENABLED=OFF
```

### 5. Validation

**Validate Config Values:**
Create a script to validate configuration before deployment:

```bash
#!/bin/bash
# validate-config.sh

CONFIG_FILE="config/config.env"

# Check required keys
REQUIRED_KEYS=("SHOP_ENV" "DOMAIN" "MAINTENANCE_ENABLED")

for key in "${REQUIRED_KEYS[@]}"; do
    if ! grep -q "^${key}=" "$CONFIG_FILE"; then
        echo "ERROR: Missing required key: $key"
        exit 1
    fi
done

# Validate SHOP_ENV values
SHOP_ENV=$(grep "^SHOP_ENV=" "$CONFIG_FILE" | cut -d'=' -f2)
if [[ ! "$SHOP_ENV" =~ ^(dev|staging|production)$ ]]; then
    echo "ERROR: Invalid SHOP_ENV value: $SHOP_ENV"
    exit 1
fi

echo "Configuration validation passed"
```

---

## Related Documentation

- [Application Monitoring Reference](./application-monitoring.md) - Dashboard monitoring features
- [Configuration Reference](./configuration-reference.md) - Dashboard configuration
- [Server Metrics Reference](./server-metrics.md) - Server-level monitoring

---

**Implementation Reference:**
- Configuration loading: `src/Watchers/ShopWatcher.php:148-166`
- Parser implementation: `src/Utils/Tools.php:6-28`
- Transmit filter: `config/transmit.json`

**Last Updated:** 2026-01-04
**Version:** 1.2.0
