# Dashboard Internal API Reference

This document describes the internal API endpoints provided by the MATA Dashboard for HTMX interactions, AJAX requests, and frontend dynamic updates.

## Overview

The dashboard exposes internal endpoints for:
- Dynamic content updates via HTMX
- JSON data for charts and visualizations
- Modal content rendering
- Real-time server metrics
- Event and alarm management

### Base URL Patterns

All dashboard endpoints follow these patterns:
- **Authentication**: `/login`, `/logout`
- **Dashboard Views**: `/watch/*`
- **Metrics API**: `/api/metrics/*`

## Authentication Endpoints

### POST /login

Authenticate user and create session.

**Content-Type:** `application/x-www-form-urlencoded`

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `username` | string | Yes | User login name |
| `password` | string | Yes | User password |

**Example Request:**

```http
POST /login HTTP/1.1
Host: dashboard.example.com
Content-Type: application/x-www-form-urlencoded

username=admin&password=secure_password
```

**Success Response:**
- **HTTP 302 Redirect** to `/watch` (dashboard)
- **Set-Cookie**: Session cookie (HttpOnly, Secure)

**Error Response:**
- **HTTP 200** with login form and error message
- Invalid credentials message displayed

---

### GET /login

Display login form.

**Response:** HTML login page

---

### GET /logout

Destroy session and redirect to login.

**Response:**
- **HTTP 302 Redirect** to `/login`
- Session cookie cleared

---

## Dashboard View Endpoints

All `/watch/*` endpoints require authentication. Unauthenticated requests are redirected to `/login`.

### GET /watch

Main dashboard page displaying all servers and applications.

**Response:** Full HTML page with:
- Server overview cards
- Application status indicators
- Navigation tabs (Servers, Databases, Libraries, Metrics, Events, Alarms)
- Initial data load

**HTMX Integration:**
- Auto-refreshes specific sections
- Polls endpoints for updates
- Lazy-loads modal content

---

### GET /watch/servers

Render servers overview section (HTMX partial).

**Query Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| None | - | Returns current cached data |

**Response:** HTML fragment containing server cards

**Example HTMX Usage:**

```html
<div hx-get="/watch/servers"
     hx-trigger="every 30s"
     hx-swap="innerHTML">
  <!-- Server cards rendered here -->
</div>
```

---

### GET /watch/servers/refresh

Force refresh servers data and render updated view.

**Behavior:**
- Bypasses cache (force refresh)
- Fetches fresh data from all configured nodes
- Updates cache with new data
- Returns updated HTML

**Response:** HTML fragment with fresh server data

**Example HTMX Usage:**

```html
<button hx-get="/watch/servers/refresh"
        hx-target="#servers-container"
        hx-swap="innerHTML">
  Refresh Servers
</button>
```

---

### GET /watch/app

Render single application view.

**Query Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `server` | string | Yes | Server identifier |
| `app` | string | Yes | Application code |

**Example Request:**

```http
GET /watch/app?server=production-web&app=ecommerce HTTP/1.1
```

**Response:** HTML fragment with application details

---

### GET /watch/app/refresh

Force refresh single application data.

**Query Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `server` | string | Yes | Server identifier |
| `app` | string | Yes | Application code |

**Response:** HTML fragment with fresh application data

---

### GET /watch/databases

Render databases overview section.

**Response:** HTML fragment containing database cards for all servers

**Example Response Structure:**
```html
<div class="databases-container">
  <div class="database-card">
    <h3>MySQL 8.0</h3>
    <p>Status: Running</p>
    <p>Connections: 12/150</p>
  </div>
  <!-- More database cards -->
</div>
```

---

### GET /watch/databases/refresh

Force refresh databases data.

**Response:** HTML fragment with fresh database information

---

### GET /watch/libraries

Render libraries overview for all applications.

**Response:** HTML fragment containing libraries comparison table

---

### GET /watch/libraries/json

Retrieve libraries data in JSON format for frontend processing.

**Response Format:**

```json
{
  "production-web": {
    "ecommerce": {
      "packages": {
        "laravel/framework": "^11.0",
        "guzzlehttp/guzzle": "^7.8"
      },
      "total_count": 42
    }
  }
}
```

**Content-Type:** `application/json`

---

### GET /watch/cronjobs

Render cronjobs monitoring overview.

**Response:** HTML fragment showing:
- Cron job health status
- Last execution times
- Execution frequency
- Error indicators

---

### GET /watch/events

Render events log view.

**Response:** HTML fragment with event log table

**Displayed Information:**
- Event timestamp
- Server/application context
- Event type and severity
- Event message
- Pagination controls

---

### GET /watch/events/json

Retrieve events data in JSON format.

**Query Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `limit` | integer | 100 | Maximum events to return |
| `offset` | integer | 0 | Pagination offset |
| `server` | string | null | Filter by server |
| `level` | string | null | Filter by severity (info, warning, critical) |

**Example Request:**

```http
GET /watch/events/json?limit=50&level=critical HTTP/1.1
```

**Response Format:**

```json
{
  "events": [
    {
      "id": 1234,
      "datetime": "2025-11-02T10:15:23Z",
      "server": "production-web",
      "app": "ecommerce",
      "type": "threshold_exceeded",
      "level": "critical",
      "message": "CPU usage exceeded 90%: 95.2%",
      "context": "cpu_usage"
    }
  ],
  "total": 523,
  "limit": 50,
  "offset": 0
}
```

**Content-Type:** `application/json`

---

### GET /watch/alarms

Render alarms log view.

**Response:** HTML fragment with alarm log table showing:
- Alarm timestamp
- Associated event
- Notification status
- Recipients

---

### GET /watch/alarms/json

Retrieve alarms data in JSON format.

**Query Parameters:** Similar to `/watch/events/json`

**Response Format:**

```json
{
  "alarms": [
    {
      "id": 456,
      "datetime": "2025-11-02T10:16:00Z",
      "event_id": 1234,
      "server": "production-web",
      "type": "email",
      "channel": "critical",
      "status": "sent",
      "recipients": ["ops@example.com"]
    }
  ],
  "total": 89,
  "limit": 50,
  "offset": 0
}
```

**Content-Type:** `application/json`

---

### GET /watch/settings

Render settings overview page.

**Response:** HTML fragment showing:
- Server configurations
- Threshold settings
- Cache TTL configuration
- Monitoring environment

---

### POST /watch/database-notice/ack

Acknowledge database setup notice.

**Response:**
- **HTTP 200** Success
- Updates user preferences

---

## Modal Endpoints

Dynamic modal content endpoints for detailed views.

### GET /watch/modal/{server}/{app}/readme

Render application README modal content.

**Path Parameters:**
- `server`: Server identifier
- `app`: Application code

**Response:** HTML modal content with Markdown-rendered README

**Example Response:**
```html
<div class="modal-content">
  <h1>E-Commerce Platform</h1>
  <h2>Overview</h2>
  <p>This application handles...</p>
  <!-- Rendered Markdown content -->
</div>
```

---

### GET /watch/modal/{server}/{app}/readme/refresh

Force refresh README content from node.

**Response:** Fresh HTML modal content

---

### GET /watch/modal/{server}/{app}/errorlog

Render application error log modal content.

**Response:** HTML modal with:
- Log file information (size, last modified)
- Error log entries
- Filtering controls
- Line numbers

---

### GET /watch/modal/{server}/{app}/errorlog/refresh

Force refresh error log from node.

**Response:** Fresh error log modal content

---

### GET /watch/modal/{server}/{app}/errorlog/json

Retrieve error log in JSON format.

**Response Format:**

```json
{
  "logs": [
    "[2025-11-02 10:15:23] production.ERROR: Database connection failed",
    "[2025-11-02 10:14:18] production.ERROR: Undefined array key"
  ],
  "metadata": {
    "size_kb": 2048,
    "line_count": 1523,
    "last_modified": "2025-11-02T10:15:23Z"
  }
}
```

**Content-Type:** `application/json`

---

### GET /watch/modal/{server}/info

Render server information modal.

**Response:** HTML modal with:
- OS and kernel version
- PHP version and extensions
- Installed packages count
- Network configuration
- Filesystem information

---

### GET /watch/modal/{server}/info/refresh

Force refresh server information.

**Response:** Fresh server info modal content

---

## Metrics API Endpoints

JSON API endpoints for charts and metrics visualization.

### GET /api/metrics/server/{server}/{metric}/json

Retrieve time-series data for a specific server metric.

**Path Parameters:**
- `server`: Server identifier
- `metric`: Metric name (cpu_usage, memory_used, disk_usage, process_count)

**Query Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `period` | string | 24h | Time period (1h, 6h, 24h, 7d, 30d) |
| `granularity` | string | auto | Data granularity (raw, hourly, daily) |

**Example Request:**

```http
GET /api/metrics/server/production-web/cpu_usage/json?period=24h HTTP/1.1
```

**Response Format:**

```json
{
  "metric": "cpu_usage",
  "server": "production-web",
  "period": "24h",
  "granularity": "hourly",
  "data": [
    {
      "timestamp": "2025-11-02T00:00:00Z",
      "value": 45.2
    },
    {
      "timestamp": "2025-11-02T01:00:00Z",
      "value": 38.7
    }
  ],
  "stats": {
    "min": 28.5,
    "max": 87.3,
    "avg": 52.1,
    "current": 45.2
  }
}
```

**Content-Type:** `application/json`

---

### GET /api/metrics/server/{server}/compare/json

Retrieve multiple metrics for comparison charts.

**Query Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `metrics` | string | all | Comma-separated metric names |
| `period` | string | 24h | Time period |

**Example Request:**

```http
GET /api/metrics/server/production-web/compare/json?metrics=cpu_usage,memory_used&period=6h
```

**Response Format:**

```json
{
  "server": "production-web",
  "period": "6h",
  "metrics": {
    "cpu_usage": [
      {"timestamp": "2025-11-02T10:00:00Z", "value": 45.2}
    ],
    "memory_used": [
      {"timestamp": "2025-11-02T10:00:00Z", "value": 2147483648}
    ]
  }
}
```

**Content-Type:** `application/json`

---

### GET /api/metrics/server/{server}/process-count

Render process count modal with detailed breakdown.

**Response:** HTML modal content showing:
- Total processes
- PHP processes
- SQL processes
- Other processes
- Process list table

---

### GET /api/metrics/dashboard

Retrieve dashboard-wide metrics summary.

**Response Format:**

```json
{
  "summary": {
    "total_servers": 5,
    "servers_online": 5,
    "servers_offline": 0,
    "total_apps": 12,
    "apps_reachable": 11,
    "apps_unreachable": 1,
    "total_events_24h": 47,
    "critical_events_24h": 2,
    "alarms_sent_24h": 3
  },
  "servers": [
    {
      "id": "production-web",
      "title": "Production Server",
      "status": "online",
      "cpu_usage": 45.2,
      "memory_percent": 58.3,
      "disk_percent": 24.1
    }
  ]
}
```

**Content-Type:** `application/json`

---

## Response Formats

### HTML Responses

HTML fragment responses for HTMX integration:

**Content-Type:** `text/html; charset=UTF-8`

**Structure:**
- Semantic HTML5 markup
- Bulma CSS classes for styling
- HTMX attributes for interactivity
- Inline data attributes for JavaScript

---

### JSON Responses

JSON responses for charts and data processing:

**Content-Type:** `application/json`

**Structure:**
- Consistent field naming (snake_case)
- ISO 8601 timestamps
- Numeric values as numbers (not strings)
- Null for missing data

---

### Error Responses

**HTTP 401 Unauthorized:**
- Redirects to `/login` for HTML requests
- Returns JSON error for API requests

**HTTP 404 Not Found:**
```json
{
  "error": "Not Found",
  "message": "Server 'invalid-server' not found"
}
```

**HTTP 429 Too Many Requests:**
```
Too many requests.
```

**HTTP 500 Internal Server Error:**
```json
{
  "error": "Internal Server Error",
  "message": "Failed to fetch server data"
}
```

---

## HTMX Integration Patterns

### Auto-Refresh Pattern

```html
<div hx-get="/watch/servers"
     hx-trigger="every 30s"
     hx-swap="innerHTML">
  <!-- Content auto-refreshes every 30 seconds -->
</div>
```

### Manual Refresh Button

```html
<button hx-get="/watch/servers/refresh"
        hx-target="#servers-container"
        hx-swap="innerHTML"
        hx-indicator="#refresh-spinner">
  <span class="icon">🔄</span>
  <span>Refresh</span>
</button>
```

### Modal Loading

```html
<a hx-get="/watch/modal/production-web/ecommerce/readme"
   hx-target="#modal-content"
   hx-swap="innerHTML"
   onclick="openModal()">
  View README
</a>
```

### Lazy Loading Tabs

```html
<div class="tabs">
  <a hx-get="/watch/events"
     hx-target="#tab-content"
     hx-trigger="click"
     hx-swap="innerHTML">
    Events
  </a>
</div>
```

---

## Rate Limiting

All endpoints are subject to rate limiting:

**Implementation:**
- IP-based throttling
- Per-endpoint limits
- Configurable in application code

**Limits:**
- Dashboard views: High limit (rarely triggered)
- API endpoints: Medium limit
- Refresh endpoints: Lower limit to prevent abuse

**Response:** HTTP 429 when limit exceeded

---

## Caching Behavior

### Cache Headers

Dashboard endpoints may include cache control headers:

```http
Cache-Control: no-cache, no-store, must-revalidate
Pragma: no-cache
Expires: 0
```

### Server-Side Caching

- **Node API responses**: Cached per TTL configuration
- **Rendered HTML**: Not cached (always fresh)
- **JSON data**: May be cached briefly for repeated requests

### Cache Bypass

Force refresh endpoints bypass cache:
- `/watch/servers/refresh`
- `/watch/databases/refresh`
- `/watch/modal/.../refresh`

---

## Security Considerations

### Session Security

- **HttpOnly Cookies**: Session cookies not accessible via JavaScript
- **Secure Flag**: HTTPS-only session cookies
- **CSRF Protection**: May be implemented for POST endpoints
- **Session Timeout**: Automatic logout after inactivity

### Input Validation

All parameters are validated:
- Server IDs: Alphanumeric, hyphens, dots, underscores
- Metric names: Predefined whitelist
- Pagination values: Positive integers with max limits

### SQL Injection Prevention

- Prepared statements for all database queries
- Parameterized queries via Database wrapper
- No raw SQL from user input

---

## Performance Optimization

### Response Optimization

- **Partial Rendering**: HTMX endpoints return minimal HTML
- **JSON Compression**: Gzip enabled for JSON responses
- **Lazy Loading**: Modal content loaded on-demand
- **Progressive Enhancement**: JavaScript not required for basic functionality

### Database Optimization

- **Indexed Queries**: Event/alarm tables have optimized indexes
- **Pagination**: Large datasets paginated by default
- **Query Caching**: Frequently accessed data cached

---

## Testing Endpoints

### Using cURL

```bash
# Authenticate and save session cookie
curl -c cookies.txt -X POST \
  -d "username=admin&password=secure_password" \
  http://localhost:8001/login

# Access protected endpoint
curl -b cookies.txt \
  http://localhost:8001/watch/servers

# Get JSON data
curl -b cookies.txt \
  http://localhost:8001/watch/events/json?limit=10
```

### Using Browser DevTools

1. Login to dashboard
2. Open Network tab
3. Trigger HTMX actions
4. Inspect XHR requests and responses

---

## Related Documentation

- [Node API Reference](node-api.md) - External node API
- [Hooks & Extensions](hooks.md) - Extension points
- [Architecture Overview](../architecture/overview.md) - System design
