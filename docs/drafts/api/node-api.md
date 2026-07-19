# Node API Reference

This document describes the API that MATA Nodes (monitored servers) must expose to communicate with the MATA Dashboard.

## Overview

MATA Nodes are lightweight API endpoints deployed on monitored servers. They expose read-only endpoints that provide server metrics, application status, and log data to the central dashboard.

### Key Characteristics

- **Read-Only Operations**: Nodes never accept commands or modifications
- **Stateless**: Each request is independent
- **Encrypted Communication**: API key authentication with timestamp validation
- **JSON Responses**: All endpoints return JSON data
- **Minimal Performance Impact**: Lightweight data collection

## Authentication

All requests to Node APIs must include authentication via the `Auth` header with a Bearer token.

### Request Headers

```http
Auth: Bearer {api_key}
Accept: application/json
```

### API Key Configuration

API keys are configured in:
- **Dashboard**: `config/servers.json` (api_key field)
- **Node**: Node configuration file (must match dashboard)

**Security Requirements:**
- Minimum 32 characters
- Generate using: `openssl rand -hex 32`
- Rotate regularly (recommended: every 90 days)
- Use HTTPS for all communications

### Example Authenticated Request

```bash
curl -H "Auth: Bearer your_secure_api_key_here_32_characters" \
     -H "Accept: application/json" \
     https://monitored-server.com/mata-node/api/server-data
```

## API Endpoints

### Base URL

All endpoints are relative to the configured `api_url` in `config/servers.json`:

```json
{
  "production-web-server": {
    "api_url": "https://your-server.com/mata-node/api"
  }
}
```

### Server Data Endpoints

#### GET /server-data

Retrieve comprehensive server system metrics.

**Response Fields:**

| Field | Type | Description |
|-------|------|-------------|
| `cpu_usage` | float | CPU usage percentage (0-100) |
| `memory_used` | integer | RAM used in bytes |
| `memory_total` | integer | Total RAM in bytes |
| `disk_usage` | integer | Disk space used in bytes |
| `disk_total` | integer | Total disk space in bytes |
| `process_count` | integer | Total number of running processes |
| `file_count` | integer | Total system file count |
| `php_process_count` | integer | Number of PHP processes |
| `sql_process_count` | integer | Number of database processes |

**Example Request:**

```bash
GET /server-data HTTP/1.1
Host: monitored-server.com
Auth: Bearer your_api_key_here
Accept: application/json
```

**Example Response:**

```json
{
  "cpu_usage": 45.2,
  "memory_used": 2147483648,
  "memory_total": 8589934592,
  "disk_usage": 128849018880,
  "disk_total": 536870912000,
  "process_count": 156,
  "file_count": 485291,
  "php_process_count": 12,
  "sql_process_count": 3
}
```

**Cache TTL:** 10 seconds (configurable via `ttl.json`)

---

#### GET /server-info

Retrieve server environment information including OS details, packages, and PHP extensions.

**Response Fields:**

| Field | Type | Description |
|-------|------|-------------|
| `os` | string | Operating system name and version |
| `os_version` | string | OS version number |
| `kernel_version` | string | Kernel version string |
| `php_version` | string | PHP version installed |
| `timezone` | string | Server timezone setting |
| `packages_count` | integer | Total installed system packages |
| `php_extensions` | array | List of active PHP extensions |
| `filesystems` | array | Disk filesystem information |
| `network_interfaces` | array | Network interface configuration |

**Example Response:**

```json
{
  "os": "Ubuntu 22.04 LTS",
  "os_version": "22.04.3",
  "kernel_version": "5.15.0-76-generic",
  "php_version": "8.3.1",
  "timezone": "UTC",
  "packages_count": 1842,
  "php_extensions": [
    "Core", "date", "libxml", "openssl", "pcre", "zlib",
    "filter", "hash", "json", "mbstring", "PDO", "pdo_mysql"
  ],
  "filesystems": [
    {
      "filesystem": "/dev/sda1",
      "size": "500G",
      "used": "120G",
      "available": "380G",
      "use_percent": "24%",
      "mounted_on": "/"
    }
  ],
  "network_interfaces": [
    {
      "interface": "eth0",
      "ip": "192.168.1.100",
      "mac": "00:0a:95:9d:68:16"
    }
  ]
}
```

**Cache TTL:** 300 seconds (5 minutes)

---

#### GET /server-php

Retrieve PHP process information and statistics.

**Response Fields:**

| Field | Type | Description |
|-------|------|-------------|
| `php_process_count` | integer | Number of running PHP processes |
| `processes` | array | Detailed process information |

**Example Response:**

```json
{
  "php_process_count": 12,
  "processes": [
    {
      "pid": 1234,
      "user": "www-data",
      "cpu": 2.5,
      "mem": 1.8,
      "time": "00:05:23",
      "command": "php-fpm: pool www"
    }
  ]
}
```

**Cache TTL:** 30 seconds

---

#### GET /server-databases

Retrieve database server information and statistics.

**Response Fields:**

| Field | Type | Description |
|-------|------|-------------|
| `type` | string | Database type (MySQL, MariaDB, PostgreSQL) |
| `version` | string | Database version |
| `status` | string | Server status (running, stopped) |
| `databases` | array | List of databases |
| `connections` | integer | Active connection count |
| `uptime` | integer | Server uptime in seconds |

**Example Response:**

```json
{
  "type": "MySQL",
  "version": "8.0.33",
  "status": "running",
  "databases": [
    {"name": "production_db", "size": "2.5 GB"},
    {"name": "staging_db", "size": "512 MB"}
  ],
  "connections": 8,
  "uptime": 2592000
}
```

**Cache TTL:** 10 seconds

---

### Application Endpoints

#### GET /apps

Retrieve list of all applications/sites hosted on the server.

**Response Format:** Object with application identifiers as keys.

**Dashboard identity:** Each key is the application identifier stored by the dashboard. Keys must be globally unique across all monitored nodes. Reusing a key denotes the same application on multiple servers; its `title` and `env` must match because the dashboard stores one shared application record. Events remain server-specific. Use a server-specific suffix for deployments that must be separate applications.

**Application Object Fields:**

| Field | Type | Description |
|-------|------|-------------|
| `title` | string | Human-readable application name |
| `path` | string | Filesystem path to application |
| `url` | string | Application URL |
| `env` | string | Environment: dev, test, live |
| `maintenance` | boolean | Maintenance mode status |
| `error_count` | integer | Number of errors in log |
| `warning_count` | integer | Number of warnings in log |
| `log_filesize_kb` | integer | Error log file size in KB |
| `session_count` | integer | Active session count |
| `session_count_last` | integer | Previous session count |

**Example Response:**

```json
{
  "ecommerce": {
    "title": "E-Commerce Platform",
    "path": "/var/www/ecommerce",
    "url": "https://shop.example.com",
    "env": "live",
    "maintenance": false,
    "error_count": 3,
    "warning_count": 12,
    "log_filesize_kb": 2048,
    "session_count": 247,
    "session_count_last": 231
  },
  "admin-portal": {
    "title": "Admin Portal",
    "path": "/var/www/admin",
    "url": "https://admin.example.com",
    "env": "live",
    "maintenance": false,
    "error_count": 0,
    "warning_count": 5,
    "log_filesize_kb": 512,
    "session_count": 8,
    "session_count_last": 6
  }
}
```

**Cache TTL:** 60 seconds

---

#### GET /app/{app_code}/status

Retrieve detailed status for a specific application.

**Path Parameters:**
- `app_code`: Application identifier (e.g., "ecommerce", "admin-portal")

**Response Fields:**

| Field | Type | Description |
|-------|------|-------------|
| `title` | string | Application name |
| `url` | string | Application URL |
| `reachable` | boolean | HTTP accessibility status |
| `httpCode` | integer | HTTP response code |
| `latency_ms` | integer | Response time in milliseconds |
| `env` | string | Environment type |
| `maintenance` | boolean | Maintenance mode |
| `error_count` | integer | Error count in logs |
| `warning_count` | integer | Warning count in logs |

**Example Response:**

```json
{
  "title": "E-Commerce Platform",
  "url": "https://shop.example.com",
  "reachable": true,
  "httpCode": 200,
  "latency_ms": 142,
  "env": "live",
  "maintenance": false,
  "error_count": 3,
  "warning_count": 12
}
```

**Cache TTL:** 60 seconds

---

#### GET /{app_code}/libraries

Retrieve installed packages/libraries for a specific application.

**Path Parameters:**
- `app_code`: Application identifier

**Response Fields:**

| Field | Type | Description |
|-------|------|-------------|
| `packages` | object | Package name to version mapping |
| `total_count` | integer | Total number of packages |
| `framework` | string | Detected framework (Laravel, Symfony, etc.) |
| `framework_version` | string | Framework version |

**Example Response:**

```json
{
  "packages": {
    "laravel/framework": "^11.0",
    "guzzlehttp/guzzle": "^7.8",
    "monolog/monolog": "^3.5",
    "symfony/mailer": "^7.0"
  },
  "total_count": 42,
  "framework": "Laravel",
  "framework_version": "11.x"
}
```

**Cache TTL:** 60 seconds

---

#### GET /apps/libraries

Retrieve library information for all applications on the server.

**Response Format:** Object with application identifiers as keys, each containing library data.

**Example Response:**

```json
{
  "ecommerce": {
    "packages": {
      "laravel/framework": "^11.0",
      "guzzlehttp/guzzle": "^7.8"
    },
    "total_count": 42,
    "framework": "Laravel"
  },
  "admin-portal": {
    "packages": {
      "symfony/framework-bundle": "^7.0",
      "doctrine/orm": "^2.17"
    },
    "total_count": 38,
    "framework": "Symfony"
  }
}
```

**Cache TTL:** 60 seconds

---

### Application Logs

#### GET /{app_code}/logs/{filter}

Retrieve filtered log entries from a specific application.

**Path Parameters:**
- `app_code`: Application identifier
- `filter`: Log filter type (default: "errors")

**Filter Types:**
- `errors`: PHP fatal errors and exceptions
- `warnings`: PHP warnings and notices
- `all`: All log entries

**Response Format:** Array of log entry strings

**Example Response:**

```json
[
  "[2025-11-02 10:15:23] production.ERROR: Database connection failed {\"exception\":\"PDOException\"}",
  "[2025-11-02 10:14:18] production.ERROR: Undefined array key \"user_id\" in /var/www/app/Controllers/UserController.php:42",
  "[2025-11-02 10:12:05] production.ERROR: Call to undefined method App\\Models\\User::getFullName()"
]
```

**Cache TTL:** 30 seconds

---

#### GET /{app_code}/errorlog

Retrieve raw error log file content.

**Response Fields:**

| Field | Type | Description |
|-------|------|-------------|
| `content` | string | Raw log file content |
| `size_kb` | integer | File size in KB |
| `line_count` | integer | Number of lines |
| `last_modified` | string | Last modification timestamp |

**Example Response:**

```json
{
  "content": "[2025-11-02 10:15:23] production.ERROR: ...\n[2025-11-02 10:14:18] production.ERROR: ...",
  "size_kb": 2048,
  "line_count": 1523,
  "last_modified": "2025-11-02T10:15:23Z"
}
```

**Cache TTL:** 60 seconds

---

#### GET /{app_code}/readme

Retrieve application README documentation.

**Response Fields:**

| Field | Type | Description |
|-------|------|-------------|
| `content` | string | Raw README.md content, normally Markdown |

**Example Response:**

```json
{
  "content": "# E-Commerce Platform\n\n## Overview\n\nThis is the main e-commerce application..."
}
```

**Cache TTL:** 300 seconds (5 minutes)

---

## Response Format Standards

### Success Response

All successful responses follow this structure:

```json
{
  "field1": "value1",
  "field2": "value2",
  ...
}
```

### Error Response

When errors occur, nodes should return appropriate HTTP status codes:

**401 Unauthorized** - Invalid or missing API key
```json
{
  "error": "Unauthorized",
  "message": "Invalid API key"
}
```

**404 Not Found** - Resource not found
```json
{
  "error": "Not Found",
  "message": "Application 'invalid-app' not found"
}
```

**500 Internal Server Error** - Server-side error
```json
{
  "error": "Internal Server Error",
  "message": "Failed to retrieve server metrics"
}
```

### Cache Metadata

Dashboard responses include cache metadata (added by dashboard, not by nodes):

```json
{
  "data": { ... },
  "cache": {
    "age": 45,
    "ttl": 60,
    "from_cache": true,
    "stale": false
  }
}
```

## Rate Limiting

While nodes themselves may not implement rate limiting, the dashboard implements rate limiting per IP/endpoint to prevent abuse:

- **Default**: Configurable per-endpoint limits
- **Implementation**: Dashboard-side via `App::isRateLimited()`
- **Response**: HTTP 429 Too Many Requests

## Security Considerations

### Node Security Best Practices

1. **HTTPS Only**: Never expose Node APIs over HTTP
2. **API Key Rotation**: Rotate keys regularly (90 days recommended)
3. **Firewall Rules**: Restrict access to dashboard IP addresses
4. **Read-Only**: Nodes must never accept write operations
5. **Input Validation**: Validate all request parameters
6. **Log Monitoring**: Monitor for suspicious API access patterns

### Dashboard-Side Security

1. **Encrypted Storage**: API keys stored in config files (not database)
2. **SSL Verification**: Verify SSL certificates (configurable)
3. **Timeout Configuration**: Request timeouts prevent hanging connections
4. **Error Handling**: Graceful degradation with stale cache on failures

## Implementation Examples

### Minimal Node Implementation (PHP)

```php
<?php
// Simple API endpoint example
header('Content-Type: application/json');

// Authenticate
$apiKey = $_SERVER['HTTP_AUTH'] ?? '';
if (!hash_equals('Bearer ' . getenv('MATA_API_KEY'), $apiKey)) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Route handling
$path = $_SERVER['PATH_INFO'] ?? '/';

switch ($path) {
    case '/server-data':
        echo json_encode([
            'cpu_usage' => getCpuUsage(),
            'memory_used' => getMemoryUsed(),
            'memory_total' => getMemoryTotal(),
            'disk_usage' => getDiskUsage(),
            'disk_total' => getDiskTotal(),
            'process_count' => getProcessCount(),
            'file_count' => getFileCount(),
        ]);
        break;

    case '/apps':
        echo json_encode(getAllApplications());
        break;

    default:
        http_response_code(404);
        echo json_encode(['error' => 'Not Found']);
}
```

## Testing Node APIs

### Using cURL

```bash
# Test server data endpoint
curl -H "Auth: Bearer your_api_key" \
     https://server.com/mata-node/api/server-data

# Test applications endpoint
curl -H "Auth: Bearer your_api_key" \
     https://server.com/mata-node/api/apps

# Test specific app status
curl -H "Auth: Bearer your_api_key" \
     https://server.com/mata-node/api/app/myapp/status
```

### Expected Latency

- **Server Data**: < 100ms
- **Applications List**: < 200ms
- **Log Data**: < 500ms (depends on log size)
- **Libraries**: < 1000ms (expensive operation)

## Troubleshooting

### Common Issues

**Authentication Failures:**
- Verify API key matches between dashboard and node
- Check `Auth` header format: `Bearer {key}`
- Ensure HTTPS is configured correctly

**Empty Responses:**
- Check node error logs
- Verify filesystem permissions for reading logs/files
- Ensure required PHP extensions are installed

**Timeout Errors:**
- Increase timeout in dashboard ApiClient configuration
- Optimize node data collection (avoid expensive operations)
- Check network connectivity between dashboard and node

**Cache Issues:**
- Verify TTL configuration in `config/ttl.json`
- Check cache directory permissions (`temp/api-cache/`)
- Use force refresh endpoints to bypass cache

## Related Documentation

- [Dashboard Endpoints](dashboard-endpoints.md) - Internal dashboard API
- [Configuration Guide](../guides/configuration.md) - Server and API configuration
- [Security Guide](../guides/security.md) - Security best practices
