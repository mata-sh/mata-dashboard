# Application Monitoring Reference

Complete reference for application health monitoring capabilities in MATA Dashboard.

## Overview

MATA Dashboard provides comprehensive application-level monitoring for PHP applications running on monitored servers. This includes uptime monitoring, error detection, session tracking, and environment identification.

## Monitoring Features

### HTTP Ping Monitoring
- Application reachability testing
- Response time tracking
- Uptime percentage calculation
- SSL certificate validation

### Error Log Analysis
- PHP fatal errors detection
- Warning and notice tracking
- Error rate monitoring
- Log file growth tracking

### Session Tracking
- Active user session monitoring
- Session count trending
- Performance correlation
- Capacity planning metrics

### Maintenance Mode Detection
- Application status monitoring
- Planned downtime awareness
- User notification support

### Environment Detection
- Development vs production identification
- Environment-specific alarm handling
- Deployment safety checks

---

## HTTP Ping Monitoring

### Overview
HTTP ping monitoring tests application reachability by making periodic HTTP/HTTPS requests to configured application URLs.

### Configuration

**In servers.json:**
```json
{
    "production-server": {
        "url": "https://your-app.com/",
        "api_url": "https://your-app.com/mata-node/api",
        "api_key": "your_api_key_here"
    }
}
```

### Ping Mechanism

| Parameter | Value |
|-----------|-------|
| **Method** | HTTP GET |
| **Timeout** | 10 seconds (configurable) |
| **Frequency** | Every 5 minutes (default) |
| **Cache TTL** | 360 seconds (6 minutes) |
| **Retry Count** | 3 attempts on failure |
| **User Agent** | `MATA-Dashboard/1.0` |

### Response Validation

**Success Criteria:**
- HTTP status code: 200-299
- Response time: < 5 seconds (warning threshold)
- Valid HTML/JSON content returned

**Failure Criteria:**
- HTTP status code: 400-599 (client/server errors)
- Connection timeout (> 10 seconds)
- DNS resolution failure
- SSL certificate errors
- Network unreachable

### Ping Response Data

**Example Response:**
```json
{
    "status": "online",
    "http_code": 200,
    "response_time_ms": 234,
    "last_check": "2025-11-02T14:30:00Z",
    "uptime_percent_24h": 99.8,
    "ssl_valid": true,
    "ssl_expires": "2026-03-15",
    "maintenance_mode": false
}
```

### Response Fields

| Field | Type | Description |
|-------|------|-------------|
| `status` | String | `online`, `offline`, `degraded` |
| `http_code` | Integer | HTTP response status code |
| `response_time_ms` | Integer | Response time in milliseconds |
| `last_check` | DateTime | Timestamp of last ping |
| `uptime_percent_24h` | Float | Uptime percentage (last 24h) |
| `ssl_valid` | Boolean | SSL certificate validity |
| `ssl_expires` | Date | SSL certificate expiration date |
| `maintenance_mode` | Boolean | Application maintenance status |

### Uptime Calculation

**Formula:**
```
uptime_percent = (successful_pings / total_pings) * 100
```

**Tracking Periods:**
- **Last hour**: Rolling 60-minute window
- **Last 24 hours**: Rolling 24-hour window
- **Last 7 days**: Weekly average
- **Last 30 days**: Monthly average

**Uptime Classifications:**

| Percentage | Status | Description |
|------------|--------|-------------|
| **99.9%+** | Excellent | < 43 minutes downtime/month |
| **99.0-99.9%** | Good | 43 min - 7.2 hours downtime/month |
| **95.0-99.0%** | Fair | 7.2 - 36 hours downtime/month |
| **< 95.0%** | Poor | > 36 hours downtime/month |

### Response Time Monitoring

**Thresholds:**
- **< 500ms**: Excellent performance
- **500ms - 1s**: Good performance
- **1s - 3s**: Acceptable performance
- **3s - 5s**: Degraded performance (warning)
- **> 5s**: Poor performance (critical)

**Response Time Trends:**
Track response time trends to identify:
- Performance degradation over time
- Peak traffic impact
- Infrastructure issues
- Application optimization needs

### SSL Certificate Monitoring

**Checks:**
- Certificate validity
- Expiration date
- Certificate chain validation
- Common name matching
- Self-signed certificate detection

**Alarm Thresholds:**
- **30 days before expiry**: Warning
- **14 days before expiry**: Critical
- **7 days before expiry**: Alarm

---

## Error Log Analysis

### Overview
MATA analyzes application error logs to detect and alarm on PHP errors, warnings, and notices.

### Supported Log Formats

**PHP Error Log:**
```
[02-Nov-2025 14:30:15 UTC] PHP Fatal error: Uncaught Error: Class 'App\Models\User' not found in /var/www/app/app/Http/Controllers/UserController.php:42
[02-Nov-2025 14:31:22 UTC] PHP Warning: Undefined array key "email" in /var/www/app/app/Helpers/UserHelper.php:18
[02-Nov-2025 14:32:03 UTC] PHP Notice: Trying to access array offset on value of type null in /var/www/app/app/Services/NotificationService.php:56
```

**Laravel Log (JSON):**
```json
{
    "message": "SQLSTATE[HY000] [2002] Connection refused",
    "context": {},
    "level": 400,
    "level_name": "ERROR",
    "channel": "production",
    "datetime": "2025-11-02T14:30:15.123456Z",
    "extra": {}
}
```

### Error Classification

| Level | Severity | Description | Action Required |
|-------|----------|-------------|-----------------|
| **Fatal Error** | Critical | Execution halted | Immediate fix required |
| **Error** | High | Runtime error | Fix within 24 hours |
| **Warning** | Medium | Non-fatal issue | Review and fix |
| **Notice** | Low | Best practice violation | Fix during maintenance |
| **Deprecated** | Low | Outdated code usage | Plan upgrade |

### Error Detection

**API Endpoint:**
```
GET /api/app/{app_name}/logs?filter=errors
```

**Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `filter` | String | `errors` | `all`, `errors`, `warnings`, `notices` |
| `lines` | Integer | 100 | Number of log lines to analyze |
| `since` | DateTime | Last check | Start time for log analysis |

**Example Response:**
```json
{
    "app_name": "production-app",
    "log_file": "/var/www/app/storage/logs/laravel.log",
    "log_filesize_kb": 102400,
    "error_counts": {
        "fatal": 2,
        "error": 15,
        "warning": 47,
        "notice": 128
    },
    "recent_errors": [
        {
            "timestamp": "2025-11-02T14:30:15Z",
            "level": "fatal",
            "message": "Class 'App\\Models\\User' not found",
            "file": "/var/www/app/app/Http/Controllers/UserController.php",
            "line": 42,
            "count": 3
        }
    ],
    "error_rate_per_hour": 8.5,
    "last_analyzed": "2025-11-02T14:35:00Z"
}
```

### Error Rate Monitoring

**Calculation:**
```
error_rate = total_errors / (time_period_hours)
```

**Thresholds:**
- **< 5 errors/hour**: Normal operation
- **5-20 errors/hour**: Monitor closely
- **20-50 errors/hour**: Investigate issues
- **> 50 errors/hour**: Critical - immediate attention

### Fatal Error Detection

**Triggers Immediate Alarm:**
- Any PHP Fatal Error
- Database connection failures
- Out of memory errors
- Class/function not found errors
- Syntax errors (in production)

**Alarm Configuration:**
```json
{
    "alarms": {
        "fatal_errors_occurring": {
            "alarm_threshold_event_count": 1,
            "min_event_interval_minutes": 720,
            "min_alarm_interval_minutes": 1440
        }
    }
}
```

### Log File Size Monitoring

Monitors growth rate and absolute size of error logs.

**Thresholds (configurable in thresholds.json):**

| Level | Size | Action |
|-------|------|--------|
| **Good** | < 10 MB | Normal operation |
| **Warning** | 10-100 MB | Review log rotation |
| **Critical** | 100-250 MB | Implement rotation |
| **Alarm** | > 250 MB | Immediate action |

**Alarm Configuration:**
```json
{
    "server_data": {
        "log_filesize_kb": {
            "good": 10240,
            "warn": 102400,
            "critical": 256000,
            "alarm": 512000
        }
    }
}
```

### Error Pattern Detection

**Common Patterns:**
- Repeated errors from same file/line
- Error rate spikes during peak hours
- Progressive error accumulation
- Circular error dependencies

**Deduplication:**
MATA automatically deduplicates identical errors within configurable time windows.

---

## Session Tracking

### Overview
Monitor active user sessions to track application usage, load, and performance.

### Session Count Monitoring

**API Endpoint:**
```
GET /api/app/{app_name}/sessions
```

**Example Response:**
```json
{
    "app_name": "production-app",
    "session_count": 1247,
    "session_count_last_hour": 1189,
    "session_count_peak_24h": 2341,
    "session_storage": "files",
    "session_lifetime": 120,
    "last_updated": "2025-11-02T14:30:00Z"
}
```

### Session Metrics

| Metric | Description | Use Case |
|--------|-------------|----------|
| **Current Count** | Active sessions now | Real-time load |
| **Last Hour** | Sessions in past 60 min | Short-term trend |
| **Peak 24h** | Maximum sessions today | Capacity planning |
| **Average 24h** | Average sessions today | Baseline load |

### Session Storage Methods

**File-based Sessions:**
```php
// Count session files
$sessionCount = count(glob('/var/lib/php/sessions/sess_*'));
```

**Impact:**
- Each session = 1 file
- Contributes to `total_system_filecount` metric
- Slower on high-traffic sites
- Cleanup requires periodic purging

**Database Sessions:**
```sql
SELECT COUNT(*) FROM sessions WHERE last_activity > (NOW() - INTERVAL 120 MINUTE);
```

**Impact:**
- No file count impact
- Better performance at scale
- Centralized session management
- Easier cleanup and monitoring

**Redis/Memcached:**
```
KEYS sess:*
```

**Impact:**
- No file count impact
- Best performance
- Automatic expiration
- Distributed session support

### Session Count Thresholds

**Default Configuration:**
```json
{
    "app_data": {
        "session_count": {
            "good": 100,
            "warn": 500,
            "critical": 2500,
            "alarm": 50000
        }
    }
}
```

**Interpretation:**

| Level | Count | Interpretation |
|-------|-------|----------------|
| **Good** | < 100 | Low traffic, normal operation |
| **Warning** | 100-500 | Moderate traffic, monitor resources |
| **Critical** | 500-2500 | High traffic, check capacity |
| **Alarm** | > 2500 | Very high traffic, scaling needed |

**Adjust thresholds based on:**
- Application size and user base
- Server capacity
- Session lifetime settings
- Business peak hours

### Session Lifetime Configuration

**PHP Settings:**
```ini
; Session lifetime in seconds (2 hours)
session.gc_maxlifetime = 7200

; Garbage collection probability
session.gc_probability = 1
session.gc_divisor = 1000
```

**Impact on Metrics:**
- Longer lifetime = higher session counts
- Shorter lifetime = more frequent garbage collection
- Balance user experience vs resource usage

### Session-Based Performance Correlation

**Correlate session count with:**
- CPU usage
- Memory usage
- Database connections
- Response times
- Error rates

**Example Analysis:**
```
High Session Count (>1000) + High CPU (>80%) + Slow Response (>3s)
→ Indicates scaling needed or performance optimization required
```

---

## Maintenance Mode Detection

### Overview
Detect when applications are in maintenance mode to suppress unnecessary alarms.

### Detection Methods

**Laravel Framework:**
```php
// Check for maintenance mode file
file_exists('/var/www/app/storage/framework/down')
```

**Custom Implementation:**
```php
// Check environment variable
getenv('APP_MAINTENANCE_MODE') === 'true'
```

**HTTP Response:**
```
HTTP/1.1 503 Service Unavailable
Retry-After: 3600
```

### Maintenance Status Response

**Example:**
```json
{
    "app_name": "production-app",
    "status": "maintenance",
    "maintenance_mode": true,
    "maintenance_message": "Scheduled maintenance - back at 15:00 UTC",
    "estimated_downtime": "30 minutes",
    "maintenance_started": "2025-11-02T14:30:00Z"
}
```

### Alarm Suppression

**During Maintenance:**
- HTTP ping failures: Suppressed
- Error log alarms: Reduced sensitivity
- Session count alarms: Disabled
- Performance alarms: Informational only

**Configuration:**
```json
{
    "maintenance_mode": {
        "suppress_ping_alarms": true,
        "suppress_error_alarms": false,
        "suppress_session_alarms": true,
        "notify_on_start": true,
        "notify_on_end": true
    }
}
```

### Planned Maintenance Workflow

**Before Maintenance:**
1. Enable maintenance mode in application
2. MATA detects maintenance status
3. Adjusts alarm handling thresholds
4. Notifies administrators (optional)

**During Maintenance:**
- Continue monitoring (data collection)
- Suppress non-critical alarms
- Track maintenance duration

**After Maintenance:**
1. Disable maintenance mode
2. MATA detects normal status
3. Restore standard alarm handling
4. Verify application health
5. Notify administrators (optional)

---

## Environment Detection

### Overview
Identify application environment (development, staging, production) for environment-specific monitoring and alarm handling.

### Environment Identification

**Methods:**
1. `.env` file parsing (see [Client Configuration Reference](./client-config.md))
2. Environment variable detection
3. Server configuration
4. URL pattern matching

**Example Detection:**
```json
{
    "app_name": "myapp",
    "environment": "production",
    "debug_mode": false,
    "app_url": "https://myapp.com",
    "detected_from": "env_file"
}
```

### Environment Types

| Environment | Monitoring Profile | Alarm Sensitivity |
|-------------|-------------------|-------------------|
| **Production** | Full monitoring | High - immediate alarms |
| **Staging** | Full monitoring | Medium - aggregated alarms |
| **Development** | Basic monitoring | Low - informational only |
| **Testing** | Basic monitoring | None - logging only |

### Environment-Specific Configuration

**Production Environment:**
```json
{
    "environment": "production",
    "monitoring": {
        "ping_frequency": 300,
        "error_sensitivity": "high",
        "session_tracking": true,
        "performance_tracking": true,
        "alarm_immediately": true
    }
}
```

**Staging Environment:**
```json
{
    "environment": "staging",
    "monitoring": {
        "ping_frequency": 600,
        "error_sensitivity": "medium",
        "session_tracking": true,
        "performance_tracking": true,
        "alarm_immediately": false,
        "aggregate_alarms": true
    }
}
```

**Development Environment:**
```json
{
    "environment": "development",
    "monitoring": {
        "ping_frequency": 900,
        "error_sensitivity": "low",
        "session_tracking": false,
        "performance_tracking": false,
        "alarm_immediately": false,
        "log_only": true
    }
}
```

### Debug Mode Detection

**Warning Indicators:**
- Debug mode enabled in production
- Stack traces visible to users
- Detailed error messages exposed
- Development tools accessible

**Alarm Configuration:**
```json
{
    "security_alarms": {
        "debug_mode_in_production": {
            "enabled": true,
            "severity": "critical",
            "immediate_alarm": true
        }
    }
}
```

---

## Application Health Scoring

### Overview
MATA calculates an overall health score (0-100) for each monitored application.

### Health Score Components

| Component | Weight | Description |
|-----------|--------|-------------|
| **Uptime** | 40% | Last 24h uptime percentage |
| **Error Rate** | 30% | Errors per hour (inverse) |
| **Response Time** | 20% | Average response time (inverse) |
| **Resource Usage** | 10% | CPU/Memory impact |

### Health Score Calculation

**Formula:**
```
health_score = (
    (uptime_percent * 0.4) +
    (error_rate_score * 0.3) +
    (response_time_score * 0.2) +
    (resource_score * 0.1)
)
```

**Component Scores:**

```javascript
// Uptime (0-100)
uptime_score = uptime_percent_24h

// Error Rate (0-100, inverse)
error_rate_score = max(0, 100 - (error_rate_per_hour * 5))

// Response Time (0-100, inverse)
response_time_score = max(0, 100 - (avg_response_time_ms / 50))

// Resource Usage (0-100, inverse)
resource_score = 100 - ((cpu_usage + ram_usage) / 2)
```

### Health Score Ranges

| Score | Status | Color | Action |
|-------|--------|-------|--------|
| **90-100** | Excellent | Green | Normal monitoring |
| **75-89** | Good | Light Green | Monitor trends |
| **60-74** | Fair | Yellow | Investigate issues |
| **40-59** | Poor | Orange | Immediate review |
| **0-39** | Critical | Red | Urgent intervention |

---

## Application Monitoring API

### List All Applications
```
GET /api/apps
```

**Response:**
```json
{
    "servers": [
        {
            "server_id": "production-server",
            "apps": [
                {
                    "name": "main-app",
                    "url": "https://app.example.com",
                    "status": "online",
                    "health_score": 92,
                    "environment": "production"
                }
            ]
        }
    ]
}
```

### Get Application Status
```
GET /api/app/{app_name}/status
```

**Response:**
```json
{
    "app_name": "main-app",
    "status": "online",
    "health_score": 92,
    "ping": {
        "http_code": 200,
        "response_time_ms": 234,
        "uptime_percent_24h": 99.8
    },
    "errors": {
        "error_rate_per_hour": 2.3,
        "fatal_count_24h": 0
    },
    "sessions": {
        "current_count": 1247,
        "peak_24h": 2341
    },
    "maintenance_mode": false,
    "last_check": "2025-11-02T14:30:00Z"
}
```

### Get Application Logs
```
GET /api/app/{app_name}/logs?filter=errors&lines=100
```

### Get Application README
```
GET /api/app/{app_name}/readme
```

### Force Refresh Application Data
```
POST /api/app/{app_name}/refresh
```

---

## Best Practices

### 1. HTTP Ping Configuration
- Set appropriate timeout values (10s default)
- Use health check endpoints instead of homepage
- Monitor SSL certificate expiration
- Track response time trends

### 2. Error Log Monitoring
- Implement log rotation to prevent huge files
- Use structured logging (JSON) for easier parsing
- Set up fatal error immediate alarms
- Review error patterns weekly

### 3. Session Management
- Use database/Redis sessions for high-traffic apps
- Set appropriate session lifetime
- Monitor session growth trends
- Clean up expired sessions regularly

### 4. Maintenance Mode
- Always use maintenance mode for planned downtime
- Notify users with clear maintenance messages
- Test maintenance mode before using in production
- Document maintenance procedures

### 5. Environment Configuration
- Never enable debug mode in production
- Use environment-specific alarm thresholds
- Test monitoring in staging before production
- Document environment differences

---

## Troubleshooting

### Application Shows Offline Despite Being Accessible

**Possible Causes:**
1. Firewall blocking MATA dashboard IP
2. SSL certificate validation failure
3. Incorrect URL in servers.json
4. API key mismatch
5. Node API not responding

**Resolution:**
```bash
# Test from dashboard server
curl -v https://your-app.com

# Check API response
curl -H "X-Mata-Auth: server-id|1700000000|nonce|hmac_sha256_hex" https://your-app.com/mata-node/api/health

# Check firewall rules
iptables -L -n | grep <dashboard-ip>
```

### Error Logs Not Updating

**Possible Causes:**
1. Log file permissions
2. Incorrect log file path
3. Application not logging errors
4. Cache preventing updates

**Resolution:**
```bash
# Check log file exists and is readable
ls -la /var/www/app/storage/logs/

# Check log file permissions
chmod 644 /var/www/app/storage/logs/*.log

# Force cache refresh in MATA
# (Use refresh button in UI)
```

### Session Count Always Zero

**Possible Causes:**
1. Using Redis/Memcached (not file-based)
2. Incorrect session path
3. Sessions not being created
4. Permission issues

**Resolution:**
```bash
# For file-based sessions
ls -la /var/lib/php/sessions/ | wc -l

# For database sessions
# Configure database session query in node API

# Check PHP session configuration
php -i | grep session
```

---

## Related Documentation

- [Client Configuration Reference](./client-config.md) - Client-side .env configuration
- [Server Metrics Reference](./server-metrics.md)
- [Event System Reference](./event-system.md)
- [Threshold Configuration](./thresholds.md)
- [Configuration Reference](./configuration-reference.md)

---

**Last Updated:** 2025-11-02
**Version:** 1.0
